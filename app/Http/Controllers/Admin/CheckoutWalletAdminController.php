<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\BusinessNameRegistration;
use App\Models\Business;
use App\Models\ConsumerDeviceStepupSession;
use App\Models\WalletFxRate;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Consumer\ConsumerBusinessWalletLedgerService;
use App\Services\Consumer\ConsumerDeviceTrustService;
use App\Services\Consumer\ConsumerWalletOtpService;
use App\Services\Consumer\ConsumerWalletPushNotificationService;
use App\Services\MevonPay\MevonIdentityVerificationService;
use App\Services\MevonPay\PrivateAccountProvisionService;
use App\Services\Whatsapp\WhatsappCrossBorderP2pFxService;
use App\Services\CheckoutWallet\CheckoutWalletCountryResolver;
use App\Services\CheckoutWallet\CheckoutWalletRegionConfig;
use App\Services\Xtrapay\XtrapaySignupAttemptAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CheckoutWalletAdminController extends Controller
{
    public function __construct(
        private ConsumerBusinessWalletLedgerService $businessLedger,
        private ConsumerWalletPushNotificationService $walletPush,
        private ConsumerDeviceTrustService $deviceTrust,
        private PrivateAccountProvisionService $privateAccountProvision,
        private CheckoutWalletCountryResolver $walletCountry,
        private ConsumerWalletOtpService $walletOtp,
        private XtrapaySignupAttemptAdminService $signupAttempts,
    ) {}

    public function index(): View
    {
        return view('admin.whatsapp-wallet.index', $this->dashboardMetrics());
    }

    public function settings(): View
    {
        return view('admin.whatsapp-wallet.settings', $this->settingsFormData());
    }

    public function wallets(Request $request): View
    {
        $query = Wallet::query();

        if ($request->filled('search')) {
            $query->search((string) $request->query('search'));
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, [Wallet::STATUS_ACTIVE, Wallet::STATUS_SUSPENDED, Wallet::STATUS_ON_HOLD], true)) {
            $query->where('status', $status);
        }
        if ($request->boolean('pnd')) {
            $query->where('post_no_debit', true);
        }

        $tier = $request->query('tier');
        if ($tier !== null && $tier !== '' && is_numeric($tier)) {
            $query->where('tier', (int) $tier);
        }

        if ($request->boolean('needs_setup')) {
            $query->where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->whereNull('pin_hash')->orWhere('pin_hash', '');
                })->orWhere(function ($q2): void {
                    $q2->whereNull('sender_name')->orWhere('sender_name', '');
                });
            });
        }

        if ($request->boolean('manual_chat')) {
            $query->where('admin_bot_paused', true);
        }

        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'oldest', 'most_active'], true)) {
            $sort = 'newest';
        }

        match ($sort) {
            'oldest' => $query->orderBy('id'),
            'most_active' => $query
                ->withMax('transactions as last_activity_at', 'created_at')
                ->orderByRaw('last_activity_at IS NULL')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $wallets = $query->paginate(25)->withQueryString();

        return view('admin.whatsapp-wallet.wallets.index', [
            'wallets' => $wallets,
            'sort' => $sort,
        ]);
    }

    public function signupAttempts(): View
    {
        return view('admin.whatsapp-wallet.signup-attempts.index', [
            'signupAttempts' => $this->signupAttempts->listOpenAttempts(40),
        ]);
    }

    public function showWallet(Wallet $wallet): View
    {
        $wallet->resetDailyTransferIfNeeded();

        $wallet->loadCount([
            'transactions as bank_transfers_count' => fn ($q) => $q->where('type', WalletTransaction::TYPE_BANK_TRANSFER_OUT),
            'transactions as p2p_count' => fn ($q) => $q->p2p(),
            'transactions as topups_count' => fn ($q) => $q->where('type', WalletTransaction::TYPE_TOPUP),
        ]);
        $wallet->load(['linkedBusiness', 'referralAsReferred.referrerWallet', 'referralsAsReferrer']);

        $recentTx = $wallet->transactions()
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $pendingPayouts = $wallet->transactions()
            ->bankTransferOut()
            ->payoutPending()
            ->where('created_at', '>=', now()->subHours(48))
            ->count();

        $businessNameRegistrations = BusinessNameRegistration::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $businessNamePendingCount = BusinessNameRegistration::query()
            ->where('wallet_id', $wallet->id)
            ->pendingReview()
            ->count();

        $apiAccount = $wallet->consumerApiAccount;
        $deviceTrustEnabled = $this->deviceTrust->isEnabled();
        $trustedDevices = [];
        $transferLockMeta = [
            'high_value_transfer_blocked' => false,
            'transfer_lock_until' => null,
            'high_value_single_transfer_cap' => null,
        ];
        $stepUpRequired = false;
        $pendingStepUpSessions = 0;

        if ($apiAccount !== null) {
            $trustedDevices = $this->deviceTrust->listDevices($apiAccount);
            $transferLockMeta = $this->deviceTrust->transferLockMeta($apiAccount);
            $stepUpRequired = $this->deviceTrust->requiresStepUp($apiAccount);
            $pendingStepUpSessions = ConsumerDeviceStepupSession::query()
                ->where('consumer_wallet_api_account_id', $apiAccount->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count();
        }

        $isNigeriaWallet = $this->walletCountry->isNigeriaPayInWallet((string) $wallet->phone_e164);
        $kycProvisionConfigured = $this->privateAccountProvision->isConfigured();
        $kycPayInReadiness = $isNigeriaWallet
            ? $this->privateAccountProvision->walletPayInReadiness($wallet)
            : ['ready' => false, 'missing' => ['Tier 2 Rubies accounts are only for Nigeria wallet numbers.']];

        $otpLockout = $this->walletOtp->lockoutStatusForAdmin((string) $wallet->phone_e164);
        $emailHold = $this->signupAttempts->emailHoldStatus((string) ($wallet->kyc_email ?? ''));

        return view('admin.whatsapp-wallet.wallets.show', [
            'wallet' => $wallet,
            'recentTx' => $recentTx,
            'pendingPayouts' => $pendingPayouts,
            'businessNameRegistrations' => $businessNameRegistrations,
            'businessNamePendingCount' => $businessNamePendingCount,
            'linkableBusinesses' => Business::query()->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
            'pushStatus' => $this->walletPush->tokenStatus($wallet),
            'deviceTrustEnabled' => $deviceTrustEnabled,
            'apiAccount' => $apiAccount,
            'trustedDevices' => $trustedDevices,
            'transferLockMeta' => $transferLockMeta,
            'stepUpRequired' => $stepUpRequired,
            'pendingStepUpSessions' => $pendingStepUpSessions,
            'referralAsReferred' => $wallet->referralAsReferred,
            'referralsMadeCount' => $wallet->referralsAsReferrer->count(),
            'isNigeriaWallet' => $isNigeriaWallet,
            'kycProvisionConfigured' => $kycProvisionConfigured,
            'kycPayInReadiness' => $kycPayInReadiness,
            // Back-compat alias used by older blade snippets
            'kycPersonalReadiness' => $kycPayInReadiness,
            'otpLockout' => $otpLockout,
            'emailHold' => $emailHold,
        ]);
    }

    public function clearSignupEmailHold(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim((string) $data['email']));
        $before = $this->signupAttempts->emailHoldStatus($email);
        $result = $this->signupAttempts->clearEmailHold($email);

        Log::info('admin.wallet.signup_email_hold_cleared', [
            'email' => $email,
            'admin_id' => Auth::guard('admin')->id(),
            'before' => $before,
            'result' => $result,
        ]);

        $redirect = redirect()->route('admin.whatsapp-wallet.signup-attempts.index');

        if (($result['cleared_count'] ?? 0) < 1) {
            return $redirect->with('success', 'No email OTP hold was active for '.$email.'. They can request a code now.');
        }

        return $redirect->with(
            'success',
            'Cleared email OTP hold for '.$email.'. Ask them to tap Resend / request a new code in the app.'
        );
    }

    public function clearWalletEmailHold(Wallet $wallet): RedirectResponse
    {
        $email = strtolower(trim((string) ($wallet->kyc_email ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'This wallet has no KYC email on file to clear.');
        }

        $before = $this->signupAttempts->emailHoldStatus($email);
        $result = $this->signupAttempts->clearEmailHold($email);

        Log::info('admin.wallet.email_hold_cleared', [
            'wallet_id' => $wallet->id,
            'email' => $email,
            'admin_id' => Auth::guard('admin')->id(),
            'before' => $before,
            'result' => $result,
        ]);

        if (($result['cleared_count'] ?? 0) < 1) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('success', 'No email OTP hold was active for '.$email.'.');
        }

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', 'Cleared email OTP hold for '.$email.'. User can request a new code immediately.');
    }

    public function clearOtpLockout(Wallet $wallet): RedirectResponse
    {
        $e164 = (string) $wallet->phone_e164;
        $before = $this->walletOtp->lockoutStatusForAdmin($e164);
        $result = $this->walletOtp->clearAllLockouts($e164);

        Log::info('admin.wallet.otp_lockout_cleared', [
            'wallet_id' => $wallet->id,
            'phone_e164' => $e164,
            'admin_id' => Auth::guard('admin')->id(),
            'before' => $before,
            'result' => $result,
        ]);

        if (! $before['is_stuck'] && ! ($result['cleared_pending_otp'] ?? false)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('success', 'No OTP lockout was active for this user. Pending OTP cache cleared anyway.');
        }

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', 'OTP lockout cleared. User can request a new login code on the app or WhatsApp.');
    }

    public function queueWalletPayInAccount(Wallet $wallet): RedirectResponse
    {
        if (! auth('admin')->user()?->canMutateWalletAccounts()) {
            abort(403, 'You cannot queue wallet pay-in accounts.');
        }

        if (! $this->walletCountry->isNigeriaPayInWallet((string) $wallet->phone_e164)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'Rubies pay-in accounts are only available for Nigeria wallet numbers.');
        }

        $result = $this->privateAccountProvision->dispatchPersonalFromStoredKyc($wallet);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with($result['dispatched'] ? 'success' : 'warning', $result['message']);
    }

    public function retryWalletPayInAccount(Wallet $wallet): RedirectResponse
    {
        if (! auth('admin')->user()?->canMutateWalletAccounts()) {
            abort(403, 'You cannot retry wallet pay-in accounts.');
        }

        if (! $this->walletCountry->isNigeriaPayInWallet((string) $wallet->phone_e164)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'Rubies pay-in accounts are only available for Nigeria wallet numbers.');
        }

        $result = $this->privateAccountProvision->dispatchPersonalFromStoredKyc($wallet, forceRetry: true);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with($result['dispatched'] ? 'success' : 'warning', $result['message']);
    }

    public function updateWalletKycPayIn(Request $request, Wallet $wallet): RedirectResponse
    {
        if (! auth('admin')->user()?->canMutateWalletAccounts()) {
            abort(403, 'You cannot update wallet KYC or pay-in details.');
        }

        if (! $this->walletCountry->isNigeriaPayInWallet((string) $wallet->phone_e164)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'KYC pay-in updates are only available for Nigeria wallet numbers.');
        }

        $request->validate([
            'kyc_fname' => ['nullable', 'string', 'max:100'],
            'kyc_lname' => ['nullable', 'string', 'max:100'],
            'kyc_dob' => ['nullable', 'date_format:Y-m-d'],
            'kyc_email' => ['nullable', 'email', 'max:255'],
            'kyc_gender' => ['nullable', 'in:male,female,Male,Female'],
            'kyc_bvn' => ['nullable', 'string', 'max:20'],
            'kyc_nin' => ['nullable', 'string', 'max:20'],
            'kyc_cac' => ['nullable', 'string', 'max:100'],
            'kyc_business_name' => ['nullable', 'string', 'max:255'],
            'rubies_account_type' => ['nullable', 'in:personal,business'],
            'mevon_virtual_account_number' => ['nullable', 'string', 'max:20'],
            'mevon_bank_name' => ['nullable', 'string', 'max:100'],
            'mevon_bank_code' => ['nullable', 'string', 'max:20'],
            'mevon_account_name' => ['nullable', 'string', 'max:200'],
            'mevon_reference' => ['nullable', 'string', 'max:100'],
            'mark_provision_completed' => ['nullable', 'boolean'],
        ]);

        $updates = [];

        foreach (['kyc_fname', 'kyc_lname', 'kyc_email', 'mevon_bank_name', 'mevon_bank_code', 'mevon_account_name', 'mevon_reference'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) $request->input($field));
                $updates[$field] = $value !== '' ? $value : null;
            }
        }

        if ($request->has('rubies_account_type')) {
            $type = strtolower(trim((string) $request->input('rubies_account_type')));
            $updates['rubies_account_type'] = in_array($type, ['personal', 'business'], true) ? $type : 'personal';
        }

        $accountType = $updates['rubies_account_type']
            ?? strtolower((string) ($wallet->rubies_account_type ?? 'personal'));

        if ($accountType === 'personal') {
            // Personal permanent accounts never use CAC / company name — clear so UI & readiness stay clean.
            $updates['kyc_cac'] = null;
            $updates['kyc_business_name'] = null;
        } else {
            if ($request->has('kyc_cac')) {
                $cac = \App\Models\Business::normalizeCacRegistrationNumber((string) $request->input('kyc_cac'));
                $updates['kyc_cac'] = $cac !== '' ? $cac : null;
            }

            if ($request->has('kyc_business_name')) {
                $bizName = trim((string) $request->input('kyc_business_name'));
                $updates['kyc_business_name'] = $bizName !== '' ? $bizName : null;
            }
        }

        if ($request->has('kyc_dob')) {
            $updates['kyc_dob'] = $request->filled('kyc_dob') ? $request->input('kyc_dob') : null;
        }

        if ($request->has('kyc_gender')) {
            $gender = strtolower(trim((string) $request->input('kyc_gender')));
            $updates['kyc_gender'] = in_array($gender, ['male', 'female'], true) ? $gender : null;
        }

        if ($request->has('kyc_bvn')) {
            $bvn = preg_replace('/\D+/', '', (string) $request->input('kyc_bvn')) ?? '';
            $updates['kyc_bvn'] = strlen($bvn) === 11 ? $bvn : null;
        }

        if ($request->has('kyc_nin')) {
            $nin = preg_replace('/\D+/', '', (string) $request->input('kyc_nin')) ?? '';
            $updates['kyc_nin'] = strlen($nin) === 11 ? $nin : null;
        }

        if ($request->has('mevon_virtual_account_number')) {
            $accountNumber = preg_replace('/\D+/', '', (string) $request->input('mevon_virtual_account_number')) ?? '';
            $updates['mevon_virtual_account_number'] = strlen($accountNumber) >= 10 ? $accountNumber : null;
        }

        $shouldCompleteProvision = $request->boolean('mark_provision_completed')
            || (! empty($updates['mevon_virtual_account_number'] ?? null));

        if ($shouldCompleteProvision) {
            $updates['tier'] = Wallet::TIER_RUBIES_VA;
            $updates['private_account_provision_status'] = PrivateAccountProvisionService::STATUS_COMPLETED;
            $updates['private_account_provision_error'] = null;

            if ($wallet->tier2_provisioned_at === null) {
                $updates['tier2_provisioned_at'] = now();
            }

            if ($wallet->kyc_verified_at === null) {
                $updates['kyc_verified_at'] = now();
            }
        }

        if ($updates === []) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('warning', 'No KYC or pay-in changes were submitted.');
        }

        $wallet->update($updates);

        Log::info('admin.wallet.kyc_pay_in_updated', [
            'wallet_id' => $wallet->id,
            'admin_id' => auth('admin')->id(),
            'fields' => array_keys($updates),
            'has_account_number' => trim((string) ($updates['mevon_virtual_account_number'] ?? $wallet->mevon_virtual_account_number)) !== '',
        ]);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', 'Wallet KYC and pay-in details updated. Mevon credit webhooks will match on mevon_virtual_account_number when tier is 2.');
    }

    public function testWalletMevonIdentity(Request $request, Wallet $wallet): RedirectResponse
    {
        if (! auth('admin')->user()?->canMutateWalletAccounts()) {
            abort(403, 'You cannot test Mevon identity verification.');
        }

        if (! $this->walletCountry->isNigeriaPayInWallet((string) $wallet->phone_e164)) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'Mevon identity verify is only available for Nigeria wallet numbers.');
        }

        $firstName = trim((string) $request->input('kyc_fname', $wallet->kyc_fname));
        $lastName = trim((string) $request->input('kyc_lname', $wallet->kyc_lname));
        $dob = $request->filled('kyc_dob')
            ? (string) $request->input('kyc_dob')
            : ($wallet->kyc_dob?->format('Y-m-d') ?? '');
        $bvn = $request->filled('kyc_bvn')
            ? (string) $request->input('kyc_bvn')
            : (string) $wallet->kyc_bvn;
        $nin = $request->filled('kyc_nin')
            ? (string) $request->input('kyc_nin')
            : (string) $wallet->kyc_nin;

        $result = app(MevonIdentityVerificationService::class)->verifyPersonal(
            $firstName,
            $lastName,
            $dob,
            $bvn !== '' ? $bvn : null,
            $nin !== '' ? $nin : null,
        );

        Log::info('admin.wallet.mevon_identity_test', [
            'wallet_id' => $wallet->id,
            'admin_id' => auth('admin')->id(),
            'ok' => $result['ok'] ?? false,
            'message' => $result['message'] ?? '',
        ]);

        $message = ($result['ok'] ?? false)
            ? 'Mevon identity verify succeeded: '.($result['message'] ?? 'OK')
            : 'Mevon identity verify failed: '.($result['message'] ?? 'Unknown error');

        if (! empty($result['full_name'])) {
            $message .= ' (Registry name: '.$result['full_name'].')';
        }

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with(($result['ok'] ?? false) ? 'success' : 'error', $message);
    }

    public function revokeTrustedDevice(Wallet $wallet, int $device): RedirectResponse
    {
        $account = $wallet->consumerApiAccount;
        if ($account === null) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'This wallet has no app login account yet.');
        }

        $ok = $this->deviceTrust->revokeDevice($account, $device);
        if ($ok) {
            Log::info('admin.wallet.device_revoked', [
                'wallet_id' => $wallet->id,
                'device_id' => $device,
                'admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with($ok ? 'success' : 'error', $ok
                ? 'Trusted device revoked. User can sign in with PIN/OTP without “Verify this device”, then set up a new passkey.'
                : 'Trusted device not found.');
    }

    public function resetDeviceRequirement(Request $request, Wallet $wallet): RedirectResponse
    {
        $account = $wallet->consumerApiAccount;
        if ($account === null) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'This wallet has no app login account yet.');
        }

        $clearLock = $request->boolean('clear_transfer_lock', true);
        $result = $this->deviceTrust->adminResetDeviceRequirement($account, $clearLock);

        Log::info('admin.wallet.device_requirement_reset', [
            'wallet_id' => $wallet->id,
            'admin_id' => Auth::guard('admin')->id(),
            'result' => $result,
        ]);

        $msg = sprintf(
            'Device requirement cleared: revoked %d device(s), cleared %d step-up session(s)%s. Customer can sign in with PIN/OTP now.',
            $result['devices_revoked'],
            $result['sessions'],
            $result['transfer_lock_cleared'] ? ', and transfer lock removed' : ''
        );

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $msg);
    }

    public function clearTransferLock(Wallet $wallet): RedirectResponse
    {
        $account = $wallet->consumerApiAccount;
        if ($account === null) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'This wallet has no app login account yet.');
        }

        $cleared = $this->deviceTrust->clearTransferLock($account);
        Log::info('admin.wallet.transfer_lock_cleared', [
            'wallet_id' => $wallet->id,
            'admin_id' => Auth::guard('admin')->id(),
            'cleared' => $cleared,
        ]);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with($cleared ? 'success' : 'error', $cleared
                ? 'High-value transfer lock cleared.'
                : 'No active transfer lock on this account.');
    }

    public function clearStepUpSessions(Wallet $wallet): RedirectResponse
    {
        $account = $wallet->consumerApiAccount;
        if ($account === null) {
            return redirect()
                ->route('admin.whatsapp-wallet.wallets.show', $wallet)
                ->with('error', 'This wallet has no app login account yet.');
        }

        $result = $this->deviceTrust->clearStepUpState($account);
        Log::info('admin.wallet.stepup_cleared', [
            'wallet_id' => $wallet->id,
            'admin_id' => Auth::guard('admin')->id(),
            'result' => $result,
        ]);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', sprintf(
                'Cleared %d step-up session(s) and %d push approval(s). Customer can retry verify-device flow.',
                $result['sessions'],
                $result['approvals']
            ));
    }

    public function sendPushNotification(Request $request, Wallet $wallet): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:500',
            'screen' => 'nullable|string|max:32|in:home,history,saving,card,profile,support',
        ]);

        $result = $this->walletPush->sendAdminMessage(
            $wallet,
            (string) $validated['title'],
            (string) $validated['body'],
            isset($validated['screen']) ? (string) $validated['screen'] : null,
        );

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function linkBusiness(Request $request, Wallet $wallet): RedirectResponse
    {
        $validated = $request->validate([
            'linked_business_id' => 'nullable|integer|exists:businesses,id',
        ]);

        $linkedBusinessId = $validated['linked_business_id'] ?? null;

        if ($linkedBusinessId) {
            $business = Business::query()->findOrFail((int) $linkedBusinessId);
            $this->businessLedger->syncBalanceFromLinkedBusiness($wallet, $business);
        } else {
            app(\App\Services\Consumer\ConsumerBusinessActivityService::class)->forgetWalletCaches($wallet);
            $wallet->update(['linked_business_id' => null]);
        }

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', 'Business wallet link updated.');
    }

    public function updateWalletStatus(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->canMutateWalletAccounts(), 403);

        $validated = $request->validate([
            'status' => 'required|string|in:'.Wallet::STATUS_ACTIVE.','.Wallet::STATUS_SUSPENDED,
        ]);

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === Wallet::STATUS_ACTIVE) {
            $updates['on_hold_at'] = null;
            $updates['on_hold_reason'] = null;
        }

        $wallet->update($updates);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $validated['status'] === Wallet::STATUS_ACTIVE
                ? 'Wallet reactivated.'
                : 'Wallet suspended.');
    }

    public function updateWalletHold(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->canMutateWalletAccounts(), 403);

        $data = $request->validate([
            'action' => 'required|string|in:hold,release',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($data['action'] === 'hold') {
            $wallet->update([
                'status' => Wallet::STATUS_ON_HOLD,
                'on_hold_at' => now(),
                'on_hold_reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
            ]);
            $message = 'Wallet put on hold. Debits are blocked; credits still allowed.';
        } else {
            $wallet->update([
                'status' => Wallet::STATUS_ACTIVE,
                'on_hold_at' => null,
                'on_hold_reason' => null,
            ]);
            $message = 'Wallet hold released.';
        }

        Log::info('admin.wallet.hold', [
            'wallet_id' => $wallet->id,
            'action' => $data['action'],
            'admin_id' => auth('admin')->id(),
        ]);

        $admin = auth('admin')->user();
        app(\App\Services\Wallet\WalletUserActivityLogger::class)->log(
            $data['action'] === 'hold'
                ? \App\Models\WalletUserActivityLog::ACTION_WALLET_HOLD
                : \App\Models\WalletUserActivityLog::ACTION_WALLET_HOLD_RELEASE,
            ($data['action'] === 'hold' ? 'Put wallet on hold' : 'Released wallet hold')
                .' ('.$wallet->phone_e164.')'
                .(! empty($data['reason']) ? ': '.$data['reason'] : ''),
            null,
            $wallet,
            null,
            $wallet,
            ['reason' => $data['reason'] ?? null],
            $admin ? app(\App\Services\Wallet\WalletUserActivityLogger::class)->actorFromAdmin($admin) : null,
        );

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $message);
    }

    public function updateWalletPostNoDebit(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->canMutateWalletAccounts(), 403);

        $enabled = $request->boolean('enabled');
        $wallet->update(['post_no_debit' => $enabled]);

        Log::info('admin.wallet.post_no_debit', [
            'wallet_id' => $wallet->id,
            'enabled' => $enabled,
            'admin_id' => auth('admin')->id(),
        ]);

        $admin = auth('admin')->user();
        app(\App\Services\Wallet\WalletUserActivityLogger::class)->log(
            \App\Models\WalletUserActivityLog::ACTION_POST_NO_DEBIT,
            ($enabled ? 'Enabled' : 'Cleared').' post-no-debit on '.$wallet->phone_e164,
            null,
            $wallet,
            null,
            $wallet,
            ['enabled' => $enabled],
            $admin ? app(\App\Services\Wallet\WalletUserActivityLogger::class)->actorFromAdmin($admin) : null,
        );

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $enabled
                ? 'Post no debit enabled. Credits allowed; all debits blocked.'
                : 'Post no debit cleared.');
    }

    public function changeWalletPin(Request $request, Wallet $wallet): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->canMutateWalletAccounts(), 403);

        $data = $request->validate([
            'pin' => 'required|string|regex:/^\d{4}$/',
            'pin_confirmation' => 'required|same:pin',
        ]);

        $wallet->pin_hash = Hash::make($data['pin']);
        $wallet->pin_set_at = now();
        $wallet->pin_failed_attempts = 0;
        $wallet->pin_locked_until = null;
        $wallet->save();

        Log::info('admin.wallet.pin_changed', [
            'wallet_id' => $wallet->id,
            'admin_id' => auth('admin')->id(),
        ]);

        $admin = auth('admin')->user();
        app(\App\Services\Wallet\WalletUserActivityLogger::class)->log(
            \App\Models\WalletUserActivityLog::ACTION_PIN_CHANGED,
            'Changed PIN for wallet '.$wallet->phone_e164,
            null,
            $wallet,
            null,
            $wallet,
            [],
            $admin ? app(\App\Services\Wallet\WalletUserActivityLogger::class)->actorFromAdmin($admin) : null,
        );

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', 'Wallet PIN updated.');
    }

    public function updateBalanceAuditExempt(Request $request, Wallet $wallet): RedirectResponse
    {
        $wallet->update([
            'balance_audit_exempt' => $request->boolean('balance_audit_exempt'),
        ]);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $wallet->balance_audit_exempt
                ? 'Wallet excluded from bank float audit.'
                : 'Wallet included in bank float audit again.');
    }

    public function updateWalletBotPause(Request $request, Wallet $wallet): RedirectResponse
    {
        $validated = $request->validate([
            'admin_bot_paused' => 'required|boolean',
        ]);

        $wallet->update(['admin_bot_paused' => (bool) $validated['admin_bot_paused']]);

        return redirect()
            ->route('admin.whatsapp-wallet.wallets.show', $wallet)
            ->with('success', $wallet->admin_bot_paused
                ? 'Manual chat mode enabled — the bot will not auto-reply until the user sends START BOT or you resume it here.'
                : 'Automated bot replies resumed for this user.');
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardMetrics(): array
    {
        $walletTotal = Wallet::query()->count();
        $walletsWithPin = Wallet::query()->whereHasPinSet()->count();
        $txTotal = WalletTransaction::query()->count();
        $txLast7d = WalletTransaction::query()->where('created_at', '>=', now()->subDays(7))->count();
        $txLast30d = WalletTransaction::query()->where('created_at', '>=', now()->subDays(30))->count();

        $txByType = WalletTransaction::query()
            ->selectRaw('type, COUNT(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type')
            ->toArray();

        $walletsByCountry = [];
        Wallet::query()->select('id', 'phone_e164')->orderBy('id')->chunk(500, function ($chunk) use (&$walletsByCountry): void {
            foreach ($chunk as $w) {
                $cc = $this->countryFromPhoneE164((string) $w->phone_e164);
                $walletsByCountry[$cc] = ($walletsByCountry[$cc] ?? 0) + 1;
            }
        });
        ksort($walletsByCountry);

        $recentTx = WalletTransaction::query()
            ->with(['wallet:id,phone_e164'])
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $regions = CheckoutWalletRegionConfig::instances();
        $dialMap = CheckoutWalletRegionConfig::countryByDial();

        return [
            'walletTotal' => $walletTotal,
            'walletsWithPin' => $walletsWithPin,
            'txTotal' => $txTotal,
            'txLast7d' => $txLast7d,
            'txLast30d' => $txLast30d,
            'txByType' => $txByType,
            'walletsByCountry' => $walletsByCountry,
            'recentTx' => $recentTx,
            'regions' => is_array($regions) ? $regions : [],
            'dialMap' => is_array($dialMap) ? $dialMap : [],
            'failedPayoutCount' => WalletTransaction::countFailedBankPayoutsRecent(),
            'pendingPayoutCount' => WalletTransaction::countPendingBankPayoutsRecent(),
            'p2pCount7d' => WalletTransaction::query()->p2p()->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFormData(): array
    {
        $this->ensureTier1LimitDefaults();

        $regions = CheckoutWalletRegionConfig::instances();
        $dialMap = CheckoutWalletRegionConfig::countryByDial();
        $wa = Setting::getByGroup('whatsapp');
        $fxRates = WalletFxRate::query()->orderBy('from_currency')->orderBy('to_currency')->get();
        $legacyFx = $wa['whatsapp_cross_border_fx_rates_json'] ?? null;
        $legacyFxPairCount = is_array($legacyFx) ? count($legacyFx) : 0;

        return [
            'regions' => is_array($regions) ? $regions : [],
            'dialMap' => is_array($dialMap) ? $dialMap : [],
            'wa' => $wa,
            'fxRates' => $fxRates,
            'fxCurrencyCodes' => $this->fxCurrencyCodesForAdmin(),
            'legacyFxPairCount' => $legacyFxPairCount,
            'checkoutPayCodeCountries' => \App\Services\Whatsapp\WhatsappCheckoutPayCodePolicy::countryOptionsForAdmin(),
            'checkoutPayCodeEnabledCountries' => \App\Services\Whatsapp\WhatsappCheckoutPayCodePolicy::enabledCountries(),
        ];
    }

    /** Persist Tier 1 defaults so admin settings always show / control the live caps. */
    private function ensureTier1LimitDefaults(): void
    {
        $dailyDefault = (float) config('whatsapp.wallet.tier1_daily_transfer_limit', 50000);
        $balanceDefault = (float) config('whatsapp.wallet.tier1_max_balance', 50000);

        if (Setting::get('whatsapp_wallet_tier1_daily_transfer') === null) {
            Setting::set('whatsapp_wallet_tier1_daily_transfer', $dailyDefault, 'float', 'whatsapp', 'Tier 1 daily transfer cap');
        }
        if (Setting::get('whatsapp_wallet_tier1_max_balance') === null) {
            Setting::set('whatsapp_wallet_tier1_max_balance', $balanceDefault, 'float', 'whatsapp', 'Tier 1 max wallet balance');
        }
    }

    public function updateFxRates(Request $request): RedirectResponse
    {
        $pairs = $request->input('pairs', []);
        if (! is_array($pairs)) {
            $pairs = [];
        }

        $normalized = [];
        foreach ($pairs as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $from = strtoupper(preg_replace('/\s+/', '', (string) ($row['from_currency'] ?? '')));
            $to = strtoupper(preg_replace('/\s+/', '', (string) ($row['to_currency'] ?? '')));
            $rateRaw = $row['rate'] ?? null;
            if ($from === '' && $to === '' && ($rateRaw === null || $rateRaw === '')) {
                continue;
            }
            if (strlen($from) !== 3 || strlen($to) !== 3 || ! preg_match('/^[A-Z]{3}$/', $from) || ! preg_match('/^[A-Z]{3}$/', $to)) {
                return redirect()->route('admin.whatsapp-wallet.settings')
                    ->withErrors(['fx_rates' => 'Row #'.((int) $i + 1).': use 3-letter currency codes (A–Z only), e.g. NGN, USD.'])
                    ->withInput();
            }
            if ($from === $to) {
                return redirect()->route('admin.whatsapp-wallet.settings')
                    ->withErrors(['fx_rates' => 'Row #'.((int) $i + 1).": From and To must be different (got {$from})."])
                    ->withInput();
            }
            if (! is_numeric($rateRaw) || (float) $rateRaw <= 0) {
                return redirect()->route('admin.whatsapp-wallet.settings')
                    ->withErrors(['fx_rates' => 'Row #'.((int) $i + 1).': Rate must be a positive number.'])
                    ->withInput();
            }
            $key = $from.'_'.$to;
            if (isset($normalized[$key])) {
                return redirect()->route('admin.whatsapp-wallet.settings')
                    ->withErrors(['fx_rates' => "Duplicate pair: {$from} → {$to}."])
                    ->withInput();
            }
            $normalized[$key] = [
                'from_currency' => $from,
                'to_currency' => $to,
                'rate' => (float) $rateRaw,
            ];
        }

        DB::transaction(function () use ($normalized): void {
            WalletFxRate::query()->delete();
            foreach ($normalized as $p) {
                WalletFxRate::query()->create([
                    'from_currency' => $p['from_currency'],
                    'to_currency' => $p['to_currency'],
                    'rate' => $p['rate'],
                ]);
            }
        });

        WhatsappCrossBorderP2pFxService::forgetRatesCache();

        return redirect()->route('admin.whatsapp-wallet.settings')
            ->with('success', 'Cross-border FX rates saved ('.count($normalized).' pair'.(count($normalized) === 1 ? '' : 's').').');
    }

    /**
     * @return list<string>
     */
    private function fxCurrencyCodesForAdmin(): array
    {
        $codes = [];
        $dial = CheckoutWalletRegionConfig::countryByDial();
        if (is_array($dial)) {
            foreach ($dial as $row) {
                if (is_array($row) && ! empty($row['currency'])) {
                    $codes[strtoupper((string) $row['currency'])] = true;
                }
            }
        }
        $instances = CheckoutWalletRegionConfig::instances();
        if (is_array($instances)) {
            foreach ($instances as $row) {
                if (is_array($row) && ! empty($row['currency'])) {
                    $codes[strtoupper((string) $row['currency'])] = true;
                }
            }
        }
        $list = array_keys($codes);
        sort($list);

        return $list !== [] ? $list : ['NGN', 'NAD', 'USD', 'CAD', 'GBP', 'GHS'];
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'whatsapp_app_url' => 'nullable|string|max:500',
            'whatsapp_public_url' => 'nullable|string|max:500',
            'whatsapp_webhook_secret' => 'nullable|string|max:500',
            'whatsapp_evolution_base_url' => 'nullable|string|max:500',
            'whatsapp_evolution_api_key' => 'nullable|string|max:500',
            'whatsapp_evolution_instance_default' => 'nullable|string|max:120',
            'whatsapp_evolution_instance_namibia' => 'nullable|string|max:120',
            'whatsapp_evolution_instance_global' => 'nullable|string|max:120',
            'whatsapp_wallet_tier1_max_balance' => 'nullable|numeric|min:0',
            'whatsapp_wallet_tier1_daily_transfer' => 'nullable|numeric|min:0',
            'whatsapp_transfer_confirm_ttl_minutes' => 'nullable|integer|min:5|max:1440',
            'whatsapp_cross_border_p2p_enabled' => 'nullable|boolean',
            'whatsapp_cross_border_fx_rate_source' => 'nullable|string|in:manual,open_er_usd',
            'whatsapp_cross_border_fx_profit_margin_percent' => 'nullable|numeric|min:0|max:99.99',
            'whatsapp_cross_border_prompt_template' => 'nullable|string|max:5000',
            'whatsapp_cross_border_disabled_message' => 'nullable|string|max:2000',
            'whatsapp_cross_border_missing_rate_message' => 'nullable|string|max:2000',
            'whatsapp_self_bank_transfer_fee_enabled' => 'nullable|boolean',
            'whatsapp_self_bank_transfer_fee_percent' => 'nullable|numeric|min:0|max:25',
            'whatsapp_self_bank_transfer_fixed_fee' => 'nullable|numeric|min:0|max:10000000',
            'whatsapp_self_bank_transfer_max_fee' => 'nullable|numeric|min:0|max:10000000',
        ]);

        Setting::set('whatsapp_app_url', $validated['whatsapp_app_url'] ?: null, 'string', 'whatsapp', 'Public app URL (WHATSAPP_APP_URL override)');
        Setting::set('whatsapp_public_url', $validated['whatsapp_public_url'] ?: null, 'string', 'whatsapp', 'Base URL for wallet PIN / confirm pages');
        Setting::set('whatsapp_webhook_secret', $validated['whatsapp_webhook_secret'] ?: null, 'string', 'whatsapp', 'Evolution webhook secret (optional override)');
        Setting::set('whatsapp_evolution_base_url', $validated['whatsapp_evolution_base_url'] ?: null, 'string', 'whatsapp', 'Evolution API base URL');
        Setting::set('whatsapp_evolution_api_key', $validated['whatsapp_evolution_api_key'] ?: null, 'string', 'whatsapp', 'Evolution API key');
        Setting::set('whatsapp_evolution_instance_default', $validated['whatsapp_evolution_instance_default'] ?: null, 'string', 'whatsapp', 'Evolution instance — Nigeria / default');
        Setting::set('whatsapp_evolution_instance_namibia', $validated['whatsapp_evolution_instance_namibia'] ?: null, 'string', 'whatsapp', 'Evolution instance — Namibia');
        Setting::set('whatsapp_evolution_instance_global', $validated['whatsapp_evolution_instance_global'] ?: null, 'string', 'whatsapp', 'Evolution instance — global (optional)');
        Setting::set('whatsapp_wallet_tier1_max_balance', $validated['whatsapp_wallet_tier1_max_balance'] ?? null, 'float', 'whatsapp', 'Tier 1 max wallet balance');
        Setting::set('whatsapp_wallet_tier1_daily_transfer', $validated['whatsapp_wallet_tier1_daily_transfer'] ?? null, 'float', 'whatsapp', 'Tier 1 daily transfer cap');
        Setting::set('whatsapp_transfer_confirm_ttl_minutes', $validated['whatsapp_transfer_confirm_ttl_minutes'] ?? null, 'integer', 'whatsapp', 'Web PIN / transfer confirm link TTL (minutes)');
        Setting::set('whatsapp_cross_border_p2p_enabled', $request->boolean('whatsapp_cross_border_p2p_enabled'), 'boolean', 'whatsapp', 'Cross-border P2P with FX');
        $fxSource = $validated['whatsapp_cross_border_fx_rate_source'] ?? 'manual';
        Setting::set('whatsapp_cross_border_fx_rate_source', $fxSource, 'string', 'whatsapp', 'FX rate source: manual table vs live USD (open.er-api.com)');
        Setting::set(
            'whatsapp_cross_border_fx_profit_margin_percent',
            $validated['whatsapp_cross_border_fx_profit_margin_percent'] ?? 0,
            'float',
            'whatsapp',
            'Global FX margin % (recipient gets (100−p)% of base conversion)'
        );
        Setting::set('whatsapp_cross_border_prompt_template', $validated['whatsapp_cross_border_prompt_template'] ?: null, 'string', 'whatsapp', 'Cross-border prompt template');
        Setting::set('whatsapp_cross_border_disabled_message', $validated['whatsapp_cross_border_disabled_message'] ?: null, 'string', 'whatsapp', 'Message when cross-border off');
        Setting::set('whatsapp_cross_border_missing_rate_message', $validated['whatsapp_cross_border_missing_rate_message'] ?: null, 'string', 'whatsapp', 'Message when FX pair missing');
        Setting::set(
            'whatsapp_self_bank_transfer_fee_enabled',
            $request->boolean('whatsapp_self_bank_transfer_fee_enabled'),
            'boolean',
            'whatsapp',
            'Charge fee when user sends to their own bank account (name or fintech phone match)'
        );
        Setting::set(
            'whatsapp_self_bank_transfer_fee_percent',
            $validated['whatsapp_self_bank_transfer_fee_percent'] ?? config('whatsapp.self_bank_transfer_fee_percent', 0),
            'float',
            'whatsapp',
            'Self bank transfer fee percent (deducted from amount sent; recipient gets remainder)'
        );
        Setting::set(
            'whatsapp_self_bank_transfer_fixed_fee',
            $validated['whatsapp_self_bank_transfer_fixed_fee'] ?? config('whatsapp.self_bank_transfer_fixed_fee', 0),
            'float',
            'whatsapp',
            'Flat naira fee added on top of the percent fee for self bank transfers'
        );
        Setting::set(
            'whatsapp_self_bank_transfer_max_fee',
            $validated['whatsapp_self_bank_transfer_max_fee'] ?? config('whatsapp.self_bank_transfer_max_fee', 500),
            'float',
            'whatsapp',
            'Maximum naira fee charged on a self bank transfer (0 = no cap)'
        );

        $enabledPayCodeCountries = $request->input('whatsapp_checkout_pay_code_countries', []);
        if (! is_array($enabledPayCodeCountries)) {
            $enabledPayCodeCountries = [];
        }
        $enabledPayCodeCountries = array_values(array_unique(array_filter(array_map(
            static fn ($cc) => strtoupper(substr(preg_replace('/\s+/', '', (string) $cc), 0, 2)),
            $enabledPayCodeCountries
        ), static fn (string $cc): bool => strlen($cc) === 2)));
        Setting::set(
            'whatsapp_checkout_pay_code_enabled_countries',
            $enabledPayCodeCountries,
            'json',
            'whatsapp',
            'ISO country codes allowed to use Checkout WhatsApp Pay Code (customer-initiated checkout)'
        );

        $this->saveWhatsappRegionOverrides($request);

        WhatsappCrossBorderP2pFxService::forgetRatesCache();

        return redirect()->route('admin.whatsapp-wallet.settings')
            ->with('success', 'WhatsApp wallet settings saved.');
    }

    private function countryFromPhoneE164(string $phoneE164): string
    {
        $d = preg_replace('/\D+/', '', $phoneE164) ?? '';
        $rows = CheckoutWalletRegionConfig::countryByDial();
        if (! is_array($rows) || $rows === []) {
            return 'Other';
        }
        usort($rows, static fn ($a, $b): int => strlen((string) ($b['dial'] ?? '')) <=> strlen((string) ($a['dial'] ?? '')));
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dial = (string) ($row['dial'] ?? '');
            if ($dial !== '' && str_starts_with($d, $dial)) {
                return (string) ($row['label'] ?? $row['country'] ?? 'Unknown');
            }
        }

        return 'Other';
    }

    /**
     * Persist admin-only dial map and Evolution instance rows (merge with file config at runtime).
     */
    private function saveWhatsappRegionOverrides(Request $request): void
    {
        $dialRows = $request->input('country_dial_extra', []);
        $dialClean = [];
        if (is_array($dialRows)) {
            foreach ($dialRows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $d = preg_replace('/\D+/', '', (string) ($r['dial'] ?? '')) ?? '';
                $cc = strtoupper(substr(preg_replace('/\s+/', '', (string) ($r['country'] ?? '')), 0, 2));
                if ($d === '' || strlen($cc) !== 2) {
                    continue;
                }
                $cur = strtoupper(substr(preg_replace('/\s+/', '', (string) ($r['currency'] ?? 'USD')), 0, 3));
                if (strlen($cur) !== 3) {
                    $cur = 'USD';
                }
                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') {
                    $label = $cc;
                }
                $dialClean[] = [
                    'dial' => $d,
                    'country' => $cc,
                    'currency' => $cur,
                    'label' => $label,
                ];
            }
        }
        Setting::set(
            'whatsapp_country_dial_extra',
            $dialClean,
            'json',
            'whatsapp',
            'Admin extra country dial map (merges with config/whatsapp_wallet_regions.php; same dial replaces file entry)'
        );

        $instRows = $request->input('instances_extra', []);
        $out = [];
        if (is_array($instRows)) {
            foreach ($instRows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $cc = strtoupper(substr(preg_replace('/\s+/', '', (string) ($r['country'] ?? '')), 0, 2));
                if (strlen($cc) !== 2) {
                    continue;
                }
                $cur = strtoupper(substr(preg_replace('/\s+/', '', (string) ($r['currency'] ?? 'USD')), 0, 3));
                if (strlen($cur) !== 3) {
                    $cur = 'USD';
                }
                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') {
                    $label = $name;
                }
                $out[$name] = [
                    'country' => $cc,
                    'currency' => $cur,
                    'label' => $label,
                    'features' => [
                        'p2p' => ! empty($r['feature_p2p']),
                        'bank' => ! empty($r['feature_bank']),
                        'vtu' => ! empty($r['feature_vtu']),
                        'rentals' => ! empty($r['feature_rentals']),
                    ],
                ];
            }
        }
        Setting::set(
            'whatsapp_wallet_instances_extra',
            $out,
            'json',
            'whatsapp',
            'Admin extra Evolution instance → country (merges with config; same name replaces / extends file entry)'
        );
    }
}
