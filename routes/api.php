<?php

use App\Http\Controllers\Api\ConsumerBusinessOverdraftController;
use App\Http\Controllers\Api\ConsumerCreditFacilityController;
use App\Http\Controllers\Api\ConsumerBusinessNameRegistrationController;
use App\Http\Controllers\Api\ConsumerBusinessAccountOnboardingController;
use App\Http\Controllers\Api\ConsumerSupportController;
use App\Http\Controllers\Api\ConsumerChatController;
use App\Http\Controllers\Api\PublicSupportController;
use App\Http\Controllers\Api\ConsumerChatInternalController;
use App\Http\Controllers\Api\ConsumerVirtualCardController;
use App\Http\Controllers\Api\ConsumerMoneyRequestController;
use App\Http\Controllers\Api\ConsumerSaveTogetherController;
use App\Http\Controllers\Api\ConsumerDeviceAuthController;
use App\Http\Controllers\Api\ConsumerWalletApiController;
use App\Http\Controllers\Api\ConsumerWalletAuthController;
use App\Http\Controllers\Api\ConsumerWalletConversationController;
use App\Http\Controllers\Api\LiveSyncReceiverController;
use App\Http\Controllers\Api\CashwyreWebhookController;
use App\Http\Controllers\Api\MevonPayWebhookController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TagineBridgeController;
use App\Http\Controllers\Api\V1StatusController;
use App\Http\Controllers\Api\VtuWebhookController;
use App\Http\Controllers\Api\WhatsappWalletApiController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Controllers\Api\BroadcastVerifyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1')->middleware([
    \App\Http\Middleware\LogMerchantApiHits::class,
    \App\Http\Middleware\AuthenticateApiKey::class,
    'throttle:merchant_api',
])->group(function () {
    // Payment routes (require API key)
    Route::get('/integration/charge-settings', [IntegrationController::class, 'chargeSettings']);
    Route::post('/payment-request', [PaymentController::class, 'store']);
    Route::get('/payment/{transactionId}', [PaymentController::class, 'show']);
    // Correct wrong amount: updates payment, recalculates charges, dispatches CheckPaymentEmails to re-scan
    // emails with new amount; when an email matches, payment is approved and the same webhook is sent
    // (payment.approved, unchanged payload). Only pending, non-expired payments can be updated.
    Route::patch('/payment/{transactionId}/amount', [PaymentController::class, 'updateAmount']);
    Route::get('/payments', [PaymentController::class, 'index']);

    // Withdrawal / payout routes (require API key; POST /withdrawal and GET /banks need payout_api_enabled)
    Route::post('/withdrawal', [\App\Http\Controllers\Api\WithdrawalController::class, 'store'])
        ->middleware('throttle:merchant_payout');
    Route::get('/withdrawals', [\App\Http\Controllers\Api\WithdrawalController::class, 'index']);
    Route::get('/balance', [\App\Http\Controllers\Api\WithdrawalController::class, 'balance']);
    Route::get('/banks', [\App\Http\Controllers\Api\WithdrawalController::class, 'banks']);
    Route::post('/validate-account', [\App\Http\Controllers\Api\WithdrawalController::class, 'validateAccount'])
        ->middleware('throttle:merchant_payout');

    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/whatsapp-wallet/lookup', [WhatsappWalletApiController::class, 'lookup']);
        Route::post('/whatsapp-wallet/ensure', [WhatsappWalletApiController::class, 'ensure']);
        Route::post('/whatsapp-wallet/send-message', [WhatsappWalletApiController::class, 'sendMessage']);
        Route::post('/whatsapp-wallet/topup/virtual-account', [WhatsappWalletApiController::class, 'issueTopupVirtualAccount']);
        Route::post('/whatsapp-wallet/pay/start', [WhatsappWalletApiController::class, 'startPartnerPay']);
    });
});

// Public routes (no API key required)
Route::prefix('v1')->group(function () {
    // GET /api/v1 — JSON status; webhook_base_url from WHATSAPP_APP_URL or APP_URL (see config/whatsapp.php)
    Route::get('/', V1StatusController::class)->name('api.v1.status');

    /*
    |--------------------------------------------------------------------------
    | Xtrapay React app API (BACKEND.md contract)
    | Base: /api/v1/xtrapay
    |--------------------------------------------------------------------------
    */
    Route::prefix('xtrapay')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'status']);

        Route::prefix('auth')->group(function () {
            Route::post('register', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'register']);
            Route::post('kyc', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'kyc']);
            Route::post('otp/send', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'sendOtp']);
            Route::post('otp/verify', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'verifyOtp']);
            Route::post('login', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'login']);
            Route::post('password/forgot', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'forgotPassword']);
            Route::post('password/reset', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'resetPassword']);
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('auth/logout', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'logout']);
            Route::get('auth/session', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'session']);
            Route::post('security/pin/verify', [\App\Http\Controllers\Api\Xtrapay\AuthController::class, 'verifyPin']);

            Route::get('bootstrap', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'bootstrap']);
            Route::get('me', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'me']);
            Route::patch('me', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'updateMe']);
            Route::get('wallets', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'wallets']);
            Route::get('transactions', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'transactions']);
            Route::get('banks', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'banks']);
            Route::post('transfers/name-enquiry', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'nameEnquiry']);
            Route::get('beneficiaries', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'beneficiaries']);
            Route::get('limits', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'limits']);
            Route::put('limits', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'updateLimits']);
            Route::post('transfers', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'createTransfer']);
            Route::get('transfers/{id}', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'showTransfer']);
            Route::get('network/rails', [\App\Http\Controllers\Api\Xtrapay\AppController::class, 'networkRails']);
        });
    });

    /**
     * Checkout Broadcast verify API (CheckoutNow + merchant POS).
     * Mobile: EXPO_PUBLIC_CHECKOUT_BROADCAST_API=https://check-outpay.com/api/v1/broadcast
     */
    Route::prefix('broadcast')->group(function () {
        Route::get('health', [BroadcastVerifyController::class, 'health']);
        Route::post('verify-broadcast', [BroadcastVerifyController::class, 'verifyBroadcast'])
            ->middleware('throttle:120,1');
        Route::post('sessions/cancel', [BroadcastVerifyController::class, 'cancelSession'])
            ->middleware('throttle:120,1');
        Route::get('sessions/{sessionUuid}', [BroadcastVerifyController::class, 'sessionStatus'])
            ->middleware('throttle:120,1');
        Route::post('sessions/{sessionUuid}/expect-payment', [BroadcastVerifyController::class, 'expectPayment'])
            ->middleware('throttle:120,1');
        Route::post('presence/nudge', [BroadcastVerifyController::class, 'presenceNudge'])
            ->middleware('throttle:30,1');
        Route::post('terminals/register', [BroadcastVerifyController::class, 'registerTerminal'])
            ->middleware('throttle:30,1');
        Route::post('terminals/sync-signing-key', [BroadcastVerifyController::class, 'syncSigningKey'])
            ->middleware('throttle:30,1');
        Route::get('terminals', [BroadcastVerifyController::class, 'listTerminals'])
            ->middleware('throttle:60,1');
        Route::get('terminals/{id}', [BroadcastVerifyController::class, 'showTerminal'])
            ->middleware('throttle:60,1');
    });

    /**
     * Consumer mobile wallet API (Android/iOS): WhatsApp OTP login + Sanctum Bearer token.
     * Same whatsapp_wallets / transactions as the WhatsApp bot.
     */
    Route::prefix('public/support')->group(function () {
        Route::middleware('throttle:support-options')->group(function () {
            Route::get('options', [PublicSupportController::class, 'options']);
            Route::get('payment-lookup', [PublicSupportController::class, 'lookupPayment']);
        });
        Route::post('intake/start', [\App\Http\Controllers\Api\PublicSupportIntakeController::class, 'start'])
            ->middleware('throttle:support-start');
        Route::get('intake/{token}', [\App\Http\Controllers\Api\PublicSupportIntakeController::class, 'show'])
            ->middleware('throttle:support-poll');
        Route::post('intake/{token}/advance', [\App\Http\Controllers\Api\PublicSupportIntakeController::class, 'advance'])
            ->middleware('throttle:support-write');
        Route::post('intake/{token}/receipt', [\App\Http\Controllers\Api\PublicSupportIntakeController::class, 'receipt'])
            ->middleware('throttle:support-write');
        Route::post('intake/{token}/complete', [\App\Http\Controllers\Api\PublicSupportIntakeController::class, 'complete'])
            ->middleware('throttle:support-start');
        Route::post('conversations', [PublicSupportController::class, 'start'])
            ->middleware('throttle:support-start');
        Route::get('conversations/{token}/messages', [PublicSupportController::class, 'messages'])
            ->middleware('throttle:support-poll');
        Route::post('conversations/{token}/messages', [PublicSupportController::class, 'sendMessage'])
            ->middleware('throttle:support-write');
    });

    Route::prefix('consumer')->middleware('throttle:consumer_wallet_otp')->group(function () {
        Route::get('referrals/rules', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'rules']);
        Route::post('auth/otp/options', [ConsumerWalletAuthController::class, 'otpOptions']);
        Route::post('auth/otp/request', [ConsumerWalletAuthController::class, 'requestOtp']);
        Route::post('auth/otp/verify', [ConsumerWalletAuthController::class, 'verifyOtp']);
        Route::post('auth/register', [ConsumerWalletAuthController::class, 'register']);
        Route::post('auth/pin/verify', [ConsumerWalletAuthController::class, 'verifyPin']);
        Route::post('auth/recovery/options', [ConsumerWalletAuthController::class, 'recoveryOptions']);
        Route::post('auth/recovery/verify-p2p', [ConsumerWalletAuthController::class, 'recoveryVerifyP2p']);
        Route::post('auth/recovery/verify-bvn', [ConsumerWalletAuthController::class, 'recoveryVerifyBvn']);
        Route::post('auth/recovery/verify-name', [ConsumerWalletAuthController::class, 'recoveryVerifyName']);
        Route::post('auth/recovery/reset-pin', [ConsumerWalletAuthController::class, 'recoveryResetPin']);
        Route::post('auth/lockdown', [ConsumerWalletAuthController::class, 'lockdown']);
        Route::post('auth/lockdown/unlock/start', [ConsumerWalletAuthController::class, 'lockdownUnlockStart']);
        Route::post('auth/lockdown/unlock/email/request', [ConsumerWalletAuthController::class, 'lockdownUnlockEmailRequest']);
        Route::post('auth/lockdown/unlock/email/verify', [ConsumerWalletAuthController::class, 'lockdownUnlockEmailVerify']);
        Route::post('auth/lockdown/unlock', [ConsumerWalletAuthController::class, 'lockdownUnlock']);
        Route::post('auth/passkey/login/options', [ConsumerDeviceAuthController::class, 'passkeyLoginOptions']);
        Route::post('auth/passkey/login/verify', [ConsumerDeviceAuthController::class, 'passkeyLoginVerify']);
        Route::post('auth/device/stepup/start', [ConsumerDeviceAuthController::class, 'stepupStart']);
        Route::post('auth/device/stepup/push/request', [ConsumerDeviceAuthController::class, 'stepupPushRequest']);
        Route::get('auth/device/stepup/push/status', [ConsumerDeviceAuthController::class, 'stepupPushStatus']);
        Route::post('auth/device/stepup/bvn', [ConsumerDeviceAuthController::class, 'stepupBvn']);
        Route::post('auth/device/stepup/otp/request', [ConsumerDeviceAuthController::class, 'stepupOtpRequest']);
        Route::post('auth/device/stepup/otp/verify', [ConsumerDeviceAuthController::class, 'stepupOtpVerify']);
        Route::post('auth/device/bind/kyc', [ConsumerDeviceAuthController::class, 'bindKycDevice']);
        Route::post('auth/device/bind/options', [ConsumerDeviceAuthController::class, 'bindOptions']);
        Route::post('auth/device/bind', [ConsumerDeviceAuthController::class, 'bindDevice']);
    });

    Route::prefix('consumer')->middleware(['auth:sanctum', 'touch.consumer.app.session', 'throttle:consumer_wallet'])->group(function () {
        Route::post('auth/logout', [ConsumerWalletAuthController::class, 'logout']);
        Route::post('auth/session/end', [ConsumerWalletAuthController::class, 'endAppSession']);
        Route::post('auth/passkey/register/options', [ConsumerDeviceAuthController::class, 'passkeyRegisterOptions']);
        Route::post('auth/passkey/register/verify', [ConsumerDeviceAuthController::class, 'passkeyRegisterVerify']);
        Route::post('auth/passkey/transaction/options', [ConsumerDeviceAuthController::class, 'passkeyTransactionOptions']);
        Route::post('auth/passkey/transaction/verify', [ConsumerDeviceAuthController::class, 'passkeyTransactionVerify']);
        Route::post('auth/device/stepup/push/approve', [ConsumerDeviceAuthController::class, 'stepupPushApprove']);
        Route::post('auth/device/stepup/push/deny', [ConsumerDeviceAuthController::class, 'stepupPushDeny']);
        Route::get('auth/devices', [ConsumerDeviceAuthController::class, 'listDevices']);
        Route::delete('auth/devices/{id}', [ConsumerDeviceAuthController::class, 'revokeDevice']);
        Route::get('wallet', [ConsumerWalletApiController::class, 'showWallet']);
        Route::post('wallet/ensure', [ConsumerWalletApiController::class, 'ensure']);
        Route::get('wallet/transactions', [ConsumerWalletApiController::class, 'transactions']);
        Route::post('wallet/statement/email', [ConsumerWalletApiController::class, 'statementEmail']);
        Route::get('wallet/recipient-lookup', [ConsumerWalletApiController::class, 'recipientLookup']);
        Route::get('wallet/receive-qr', [ConsumerWalletApiController::class, 'receiveQr']);
        Route::post('wallet/scan-resolve', [ConsumerWalletApiController::class, 'scanResolve']);
        Route::post('wallet/topup/virtual-account', [ConsumerWalletApiController::class, 'issueTopupVirtualAccount']);
        Route::post('wallet/pin', [ConsumerWalletApiController::class, 'setPin']);
        Route::put('wallet/pin', [ConsumerWalletApiController::class, 'changePin']);
        Route::patch('profile/sender-name', [ConsumerWalletApiController::class, 'updateSenderName']);
        Route::patch('wallet/transfer-email-otp', [ConsumerWalletApiController::class, 'updateTransferEmailOtp']);
        Route::patch('wallet/card-notifications', [ConsumerWalletApiController::class, 'updateCardNotifications']);
        Route::patch('wallet/money-request-settings', [ConsumerMoneyRequestController::class, 'updateSettings']);
        Route::get('wallet/money-request-settings', [ConsumerMoneyRequestController::class, 'settings']);
        Route::get('wallet/money-request-blocks', [ConsumerMoneyRequestController::class, 'listBlocks']);
        Route::post('wallet/money-request-blocks', [ConsumerMoneyRequestController::class, 'storeBlock']);
        Route::delete('wallet/money-request-blocks', [ConsumerMoneyRequestController::class, 'destroyBlock']);
        Route::get('money-requests', [ConsumerMoneyRequestController::class, 'index']);
        Route::post('money-requests', [ConsumerMoneyRequestController::class, 'store']);
        Route::post('money-requests/{id}/accept', [ConsumerMoneyRequestController::class, 'accept']);
        Route::post('money-requests/{id}/decline', [ConsumerMoneyRequestController::class, 'decline']);
        Route::delete('money-requests/{id}', [ConsumerMoneyRequestController::class, 'destroy']);
        Route::get('referrals/me', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'me']);
        Route::get('referrals/invite', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'invite']);
        Route::get('referrals/list', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'list']);
        Route::get('referrals/bonuses', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'bonuses']);
        Route::get('referrals/leaderboard', [\App\Http\Controllers\Api\ConsumerReferralController::class, 'leaderboard']);
        Route::get('save-together', [ConsumerSaveTogetherController::class, 'index']);
        Route::post('save-together', [ConsumerSaveTogetherController::class, 'store']);
        Route::get('save-together/{id}', [ConsumerSaveTogetherController::class, 'show']);
        Route::post('save-together/{id}/contribute', [ConsumerSaveTogetherController::class, 'contribute']);
        Route::post('save-together/{id}/withdraw', [ConsumerSaveTogetherController::class, 'withdraw']);
        Route::post('save-together/{id}/decline', [ConsumerSaveTogetherController::class, 'decline']);
        Route::post('wallet/push-token', [ConsumerWalletApiController::class, 'registerPushToken']);
        Route::post('transfers/p2p', [ConsumerWalletApiController::class, 'transferP2p']);
        Route::post('transfers/bank', [ConsumerWalletApiController::class, 'transferBank']);
        Route::post('transfers/fee-quote', [ConsumerWalletApiController::class, 'transferFeeQuote']);
        Route::get('banks', [ConsumerWalletApiController::class, 'banks']);
        Route::get('banks/name-enquiry', [ConsumerWalletApiController::class, 'bankNameEnquiry']);
        Route::get('vtu/networks', [ConsumerWalletApiController::class, 'vtuNetworks']);
        Route::get('vtu/bill-catalog', [ConsumerWalletApiController::class, 'vtuBillCatalog']);
        Route::get('vtu/data-plans', [ConsumerWalletApiController::class, 'vtuDataPlans']);
        Route::get('vtu/tv-plans', [ConsumerWalletApiController::class, 'vtuTvPlans']);
        Route::post('vtu/electricity/verify', [ConsumerWalletApiController::class, 'vtuElectricityVerify']);
        Route::post('vtu/electricity', [ConsumerWalletApiController::class, 'vtuElectricity']);
        Route::post('vtu/tv/verify', [ConsumerWalletApiController::class, 'vtuTvVerify']);
        Route::post('vtu/tv', [ConsumerWalletApiController::class, 'vtuTv']);
        Route::post('vtu/betting/verify', [ConsumerWalletApiController::class, 'vtuBettingVerify']);
        Route::post('vtu/betting', [ConsumerWalletApiController::class, 'vtuBetting']);
        Route::post('vtu/airtime', [ConsumerWalletApiController::class, 'vtuAirtime']);
        Route::post('vtu/data', [ConsumerWalletApiController::class, 'vtuData']);
        Route::get('cards/prefill', [ConsumerVirtualCardController::class, 'prefill']);
        Route::get('cards/quote', [ConsumerVirtualCardController::class, 'quote']);
        Route::post('cards/request', [ConsumerVirtualCardController::class, 'request']);
        Route::post('cards/retry-sync', [ConsumerVirtualCardController::class, 'retrySync'])
            ->middleware('throttle:6,1');
        Route::post('cards/topup', [ConsumerVirtualCardController::class, 'topup']);
        Route::post('cards/status', [ConsumerVirtualCardController::class, 'setStatus']);
        Route::post('cards/auto-freeze', [ConsumerVirtualCardController::class, 'setAutoFreeze']);
        Route::post('cards/withdraw', [ConsumerVirtualCardController::class, 'withdraw']);
        Route::post('cards/details', [ConsumerVirtualCardController::class, 'details']);
        Route::get('cards', [ConsumerVirtualCardController::class, 'index']);
        Route::get('cards/transactions', [ConsumerVirtualCardController::class, 'transactions']);
        Route::get('kyc/tier2', [ConsumerWalletApiController::class, 'kycTier2Status']);
        Route::post('kyc/tier2/personal', [ConsumerWalletApiController::class, 'kycTier2Personal']);
        Route::post('kyc/tier2/business', [ConsumerWalletApiController::class, 'kycTier2Business']);
        Route::get('business-name-registration', [ConsumerBusinessNameRegistrationController::class, 'index']);
        Route::post('business-name-registration', [ConsumerBusinessNameRegistrationController::class, 'store']);
        Route::get('business-account/onboarding', [ConsumerBusinessAccountOnboardingController::class, 'index']);
        Route::post('business-account/onboarding', [ConsumerBusinessAccountOnboardingController::class, 'store']);
        Route::post('business-account/onboarding/password', [ConsumerBusinessAccountOnboardingController::class, 'setPassword']);
        Route::get('business/overdraft', [ConsumerBusinessOverdraftController::class, 'show']);
        Route::post('business/overdraft/apply', [ConsumerBusinessOverdraftController::class, 'apply']);
        Route::post('wallet/credit-facility/request', [ConsumerCreditFacilityController::class, 'request']);
        /** @deprecated Prefer POST consumer/wallet/conversation; kept for older app bundles. */
        Route::get('chat/messages', [ConsumerChatController::class, 'index']);
        Route::post('chat/messages', [ConsumerChatController::class, 'store']);
        Route::post('wallet/conversation', [ConsumerWalletConversationController::class, 'store']);
        Route::post('wallet/transfer/confirm-web-token', [ConsumerWalletApiController::class, 'confirmTransferWebToken']);

        Route::get('savings', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'show']);
        Route::put('savings/settings', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'updateSettings']);
        Route::get('savings/goals', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'goals']);
        Route::post('savings/goals/preview', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'previewGoal']);
        Route::post('savings/goals', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'storeGoal']);
        Route::patch('savings/goals/{goalId}', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'patchGoal']);
        Route::post('savings/deposit', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'deposit']);
        Route::post('savings/withdraw', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'withdraw']);
        Route::get('savings/locks', [\App\Http\Controllers\Api\ConsumerSavingsApiController::class, 'locks']);

        Route::prefix('support')->group(function () {
            Route::get('context', [\App\Http\Controllers\Api\ConsumerSupportContextController::class, 'show'])
                ->middleware('throttle:support-options');
            Route::get('options', [ConsumerSupportController::class, 'options'])
                ->middleware('throttle:support-options');
            Route::post('intake/start', [\App\Http\Controllers\Api\ConsumerSupportIntakeController::class, 'start'])
                ->middleware('throttle:support-start');
            Route::get('intake/{token}', [\App\Http\Controllers\Api\ConsumerSupportIntakeController::class, 'show'])
                ->middleware('throttle:support-poll');
            Route::post('intake/{token}/advance', [\App\Http\Controllers\Api\ConsumerSupportIntakeController::class, 'advance'])
                ->middleware('throttle:support-write');
            Route::post('intake/{token}/receipt', [\App\Http\Controllers\Api\ConsumerSupportIntakeController::class, 'receipt'])
                ->middleware('throttle:support-write');
            Route::post('intake/{token}/complete', [\App\Http\Controllers\Api\ConsumerSupportIntakeController::class, 'complete'])
                ->middleware('throttle:support-start');
            Route::post('conversations', [ConsumerSupportController::class, 'start'])
                ->middleware('throttle:support-start');
            Route::get('conversations/{token}/messages', [ConsumerSupportController::class, 'messages'])
                ->middleware('throttle:support-poll');
            Route::post('conversations/{token}/messages', [ConsumerSupportController::class, 'sendMessage'])
                ->middleware('throttle:support-write');

            Route::prefix('staff')->group(function () {
                Route::get('inbox', [\App\Http\Controllers\Api\ConsumerSupportStaffController::class, 'inbox'])
                    ->middleware('throttle:support-poll');
                Route::get('tickets/{ticketId}/messages', [\App\Http\Controllers\Api\ConsumerSupportStaffController::class, 'messages'])
                    ->middleware('throttle:support-poll')
                    ->whereNumber('ticketId');
                Route::post('tickets/{ticketId}/reply', [\App\Http\Controllers\Api\ConsumerSupportStaffController::class, 'reply'])
                    ->middleware('throttle:support-write')
                    ->whereNumber('ticketId');
                Route::post('tickets/{ticketId}/status', [\App\Http\Controllers\Api\ConsumerSupportStaffController::class, 'updateStatus'])
                    ->middleware('throttle:support-write')
                    ->whereNumber('ticketId');
            });
        });
    });

    Route::post('internal/consumer-chat/reply', [ConsumerChatInternalController::class, 'reply'])
        ->middleware('throttle:30,1');

    // Contabo → Namecheap merchant webhook egress (HMAC). Namecheap forwards to merchant allowlisted IPs.
    Route::post('internal/webhook-egress', [\App\Http\Controllers\Api\WebhookEgressRelayController::class, 'receive'])
        ->middleware([\App\Http\Middleware\VerifyWebhookEgressRelaySignature::class, 'throttle:120,1']);

    // Secure inbound sync receiver (live site -> this app)
    Route::post('sync/live', [LiveSyncReceiverController::class, 'receive'])
        ->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class.':api'])
        ->middleware([\App\Http\Middleware\VerifyLiveSyncSignature::class, 'throttle:live_sync']);
    Route::post('sync/live/probe', [LiveSyncReceiverController::class, 'probe'])
        ->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class.':api'])
        ->middleware([\App\Http\Middleware\VerifyLiveSyncSignature::class, 'throttle:live_sync']);
    Route::post('sync/live/batch', [LiveSyncReceiverController::class, 'receiveBatch'])
        ->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class.':api'])
        ->middleware([\App\Http\Middleware\VerifyLiveSyncSignature::class, 'throttle:live_sync']);

    // Statistics — locked (cron token); not for public / merchant use
    Route::get('/statistics', [\App\Http\Controllers\Api\StatisticsController::class, 'index'])
        ->middleware(['cron.token', 'throttle:30,1']);

    // Email webhook (for email forwarding services like Zapier)
    Route::post('/email/webhook', [\App\Http\Controllers\Api\EmailWebhookController::class, 'receive']);
    Route::get('/email/webhook', [\App\Http\Controllers\Api\EmailWebhookController::class, 'healthCheck']); // GET for health checks and testing
    Route::post('/webhook/email', [\App\Http\Controllers\Api\EmailWebhookController::class, 'receive']); // Legacy route for backward compatibility
    Route::get('/webhook/email', [\App\Http\Controllers\Api\EmailWebhookController::class, 'healthCheck']); // GET for legacy route too
    Route::get('/email/webhook/health', [\App\Http\Controllers\Api\EmailWebhookController::class, 'healthCheck'])->name('email.webhook.health');

    // Transaction check — expensive (runs email monitor); requires cron token
    Route::post('/transaction/check', [\App\Http\Controllers\Api\TransactionCheckController::class, 'checkTransaction'])
        ->middleware(['cron.token', 'throttle:6,1']);
    Route::get('/transaction/check', [\App\Http\Controllers\Api\TransactionCheckController::class, 'checkTransaction'])
        ->middleware(['cron.token', 'throttle:6,1']);

    // Webhook processing cron — requires cron token
    Route::get('/cron/process-webhooks', [\App\Http\Controllers\Cron\WebhookCronController::class, 'processWebhooks'])
        ->middleware(['cron.token', 'throttle:10,1']);
    Route::post('/cron/process-webhooks', [\App\Http\Controllers\Cron\WebhookCronController::class, 'processWebhooks'])
        ->middleware(['cron.token', 'throttle:10,1']);
    // MEVONPAY external funding webhook (account_number is source of truth)
    Route::post('/webhook/mevonpay', [MevonPayWebhookController::class, 'receive']);
    Route::post('/webhooks/mevonpay', [MevonPayWebhookController::class, 'receive']); // plural alias
    Route::post('/webhook/sla', [MevonPayWebhookController::class, 'receive']); // backward compatibility
    Route::post('/webhooks/sla', [MevonPayWebhookController::class, 'receive']); // plural alias
    Route::post('/webhook/mavonpay', [MevonPayWebhookController::class, 'receive']); // backward compatibility
    Route::post('/webhooks/mavonpay', [MevonPayWebhookController::class, 'receive']); // plural alias

    // Cashwyre virtual card webhooks
    Route::post('/webhook/cashwyre', [CashwyreWebhookController::class, 'receive']);
    Route::post('/webhooks/cashwyre', [CashwyreWebhookController::class, 'receive']);

    // VTU provider webhook (reversals/refunds/status updates)
    Route::post('/webhook/vtu', [VtuWebhookController::class, 'receive'])->middleware('throttle:120,1');
    Route::post('/webhooks/vtu', [VtuWebhookController::class, 'receive'])->middleware('throttle:120,1');

    // Squad dedicated webhooks (VTU + future payments)
    Route::post('/webhook/squad', [\App\Http\Controllers\Api\SquadWebhookController::class, 'receive'])->middleware('throttle:120,1');
    Route::post('/webhooks/squad', [\App\Http\Controllers\Api\SquadWebhookController::class, 'receive'])->middleware('throttle:120,1');

    /**
     * Tax Calculator open API
     */
    Route::prefix('tax')->middleware('throttle:30,1')->group(function () {
        Route::post('business', [\App\Http\Controllers\Api\TaxController::class, 'saveBusiness']);
        Route::post('personal', [\App\Http\Controllers\Api\TaxController::class, 'savePersonal']);
    });

    Route::post('nigtax/visit', [\App\Http\Controllers\Api\NigtaxVisitController::class, 'store'])
        ->middleware('throttle:120,1');

    /**
     * Evolution API → Checkout (OTP link for renters). Configure webhook to POST here.
     * Header X-Checkout-WhatsApp-Secret or ?secret= when WHATSAPP_WEBHOOK_SECRET is set.
     */
    Route::post('whatsapp/webhook', [WhatsappWebhookController::class, 'receive'])
        ->middleware('throttle:120,1');
    Route::get('whatsapp/webhook', [WhatsappWebhookController::class, 'health']);

    Route::prefix('nigtax/certified')->middleware('throttle:60,1')->group(function () {
        Route::get('settings', [\App\Http\Controllers\Api\NigtaxCertifiedPublicController::class, 'settings']);
        Route::post('orders', [\App\Http\Controllers\Api\NigtaxCertifiedPublicController::class, 'store']);
        Route::get('orders/{transaction_id}', [\App\Http\Controllers\Api\NigtaxCertifiedPublicController::class, 'show']);
    });

    Route::prefix('nigtax/pro')->middleware('throttle:60,1')->group(function () {
        Route::get('config', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'config']);
        Route::post('password/email', [\App\Http\Controllers\Api\NigtaxProPasswordResetController::class, 'sendResetLinkEmail'])
            ->middleware('throttle:6,1');
        Route::post('membership/checkout', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'startMembershipCheckout'])
            ->middleware('throttle:10,1');
        Route::get('membership/payment/{transaction_id}', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'membershipPaymentStatus']);
        Route::post('register', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'register'])
            ->middleware('throttle:10,1');
        Route::post('login', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'login'])
            ->middleware('throttle:20,1');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'me']);
            Route::post('logout', [\App\Http\Controllers\Api\NigtaxProAuthController::class, 'logout']);
            Route::get('queries', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'index']);
            Route::post('queries', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'store'])
                ->middleware('throttle:30,1');
            Route::get('queries/{id}', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'show'])->whereNumber('id');
            Route::get('queries/{id}/statement', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'downloadStatement'])->whereNumber('id');
            Route::delete('queries/{id}/statement', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'destroyStatement'])->whereNumber('id');
            Route::delete('queries/{id}', [\App\Http\Controllers\Api\NigtaxProQueryController::class, 'destroy'])->whereNumber('id');
        });
    });

    /**
     * Rentals public API (no auth required)
     */
    Route::prefix('rentals')->group(function () {
        // Catalog
        Route::get('categories', [\App\Http\Controllers\Api\Rentals\ItemController::class, 'categories']);
        Route::get('featured', [\App\Http\Controllers\Api\Rentals\ItemController::class, 'featured']);
        Route::get('items', [\App\Http\Controllers\Api\Rentals\ItemController::class, 'index']);
        Route::get('items/{id}/reviews', [\App\Http\Controllers\Api\Rentals\ItemReviewsController::class, 'index'])
            ->whereNumber('id');
        Route::get('items/{id}/unavailable-dates', [\App\Http\Controllers\Api\Rentals\ItemController::class, 'unavailableDates'])
            ->whereNumber('id');
        Route::get('items/{slug}', [\App\Http\Controllers\Api\Rentals\ItemController::class, 'show']);

        // KYC verification (public AJAX-style endpoint)
        Route::post('kyc/verify', [\App\Http\Controllers\Api\Rentals\KycController::class, 'verify']);
        // Dynamic possible banks for an account number (NUBAN-backed)
        Route::post('kyc/banks', [\App\Http\Controllers\Api\Rentals\KycController::class, 'banksForAccount']);
        // All known banks from Checkout DB (cached from NUBAN responses)
        Route::get('banks/suggestions', [\App\Http\Controllers\Api\Rentals\KycController::class, 'bankSuggestionsForAccount']);
        Route::get('banks', [\App\Http\Controllers\Api\Rentals\KycController::class, 'banksFromDatabase']);

        // Renter auth (token-based)
        Route::post('auth/register', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'register']);
        Route::post('auth/login', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'login']);

        // Forgot password – send reset link email
        Route::post('password/email', [\App\Http\Controllers\Api\Rentals\PasswordResetController::class, 'sendResetLinkEmail']);
    });
});

/**
 * Rentals authenticated API (requires Sanctum token, authenticating Renter model)
 */
Route::prefix('v1/rentals')
    ->middleware(['auth:sanctum', 'renter_active'])
    ->group(function () {
        // Current renter
        Route::get('me', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'me']);
        Route::post('auth/logout', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'logout']);
        Route::post('me/email/resend-verification', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'resendEmailVerification']);
        Route::post('me/email/verify-pin', [\App\Http\Controllers\Api\Rentals\AuthController::class, 'verifyEmailPin']);

        // KYC update for renter
        Route::post('me/kyc', [\App\Http\Controllers\Api\Rentals\KycController::class, 'update']);
        Route::post('me/kyc-id', [\App\Http\Controllers\Api\Rentals\KycController::class, 'uploadId']);

        // Checkout flow
        Route::post('checkout/quote', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'quote']);
        Route::post('checkout/submit', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'submit']);

        // Account management
        Route::post('password/change', [\App\Http\Controllers\Api\Rentals\AccountController::class, 'changePassword']);
        Route::get('wallet', [\App\Http\Controllers\Api\Rentals\AccountController::class, 'wallet']);
        Route::post('devices/register', [\App\Http\Controllers\Api\Rentals\DeviceController::class, 'register']);
        Route::delete('devices/register', [\App\Http\Controllers\Api\Rentals\DeviceController::class, 'unregister']);

        Route::get('favorites', [\App\Http\Controllers\Api\Rentals\FavoritesController::class, 'index']);
        Route::post('favorites', [\App\Http\Controllers\Api\Rentals\FavoritesController::class, 'store']);
        Route::delete('favorites/{itemId}', [\App\Http\Controllers\Api\Rentals\FavoritesController::class, 'destroy'])
            ->whereNumber('itemId');
        Route::delete('favorites', [\App\Http\Controllers\Api\Rentals\FavoritesController::class, 'destroy']);

        Route::get('support/messages', [\App\Http\Controllers\Api\Rentals\SupportController::class, 'messages']);
        Route::post('wallet/fund', [\App\Http\Controllers\Api\Rentals\AccountController::class, 'fundWallet']);
        Route::post('wallet/fund/check', [\App\Http\Controllers\Api\Rentals\AccountController::class, 'checkWalletFunding']);
        Route::post('me/profile', [\App\Http\Controllers\Api\Rentals\AccountController::class, 'updateProfile']);

        // Renter rentals
        Route::get('requests', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'listRentals']);
        Route::get('requests/{rental}', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'showRental'])
            ->whereNumber('rental');
        Route::get('requests/{rental}/rebundle-preview', [\App\Http\Controllers\Api\Rentals\RebundleController::class, 'preview'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/rebundle', [\App\Http\Controllers\Api\Rentals\RebundleController::class, 'rebundle'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/check-payment', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'checkPayment'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/request-return', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'requestReturn'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/fulfillment', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'setFulfillment'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/return-method', [\App\Http\Controllers\Api\Rentals\CheckoutController::class, 'setReturnMethod'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/reviews', [\App\Http\Controllers\Api\Rentals\ItemReviewsController::class, 'store'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/cancel', [\App\Http\Controllers\Api\Rentals\RentalRequestActionsController::class, 'cancel'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/condition-report', [\App\Http\Controllers\Api\Rentals\RentalRequestActionsController::class, 'conditionReport'])
            ->whereNumber('rental');
        Route::post('requests/{rental}/disputes', [\App\Http\Controllers\Api\Rentals\RentalRequestActionsController::class, 'openDispute'])
            ->whereNumber('rental');
        Route::get('requests/{rental}/disputes', [\App\Http\Controllers\Api\Rentals\RentalRequestActionsController::class, 'listDisputes'])
            ->whereNumber('rental');
        Route::post('disputes/{dispute}/resolve', [\App\Http\Controllers\Api\Rentals\RentalRequestActionsController::class, 'resolveDispute'])
            ->whereNumber('dispute');

        Route::get('support/tickets', [\App\Http\Controllers\Api\Rentals\SupportTicketsController::class, 'index']);
        Route::post('support/tickets', [\App\Http\Controllers\Api\Rentals\SupportTicketsController::class, 'store']);
        Route::get('support/tickets/{ticket}/messages', [\App\Http\Controllers\Api\Rentals\SupportTicketsController::class, 'messages'])
            ->whereNumber('ticket');
        Route::post('support/tickets/{ticket}/messages', [\App\Http\Controllers\Api\Rentals\SupportTicketsController::class, 'postMessage'])
            ->whereNumber('ticket');

        /**
         * Business management (authenticated via rentals token + email→Business)
         */
        Route::post('business/apply', [\App\Http\Controllers\Api\Rentals\Business\VendorApplicationController::class, 'apply']);
        Route::get('business/application', [\App\Http\Controllers\Api\Rentals\Business\VendorApplicationController::class, 'show']);
        Route::get('business/summary', \App\Http\Controllers\Api\Rentals\Business\SummaryController::class);
        Route::get('business/rentals', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'index']);
        Route::post('business/rentals', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'store']);
        Route::get('business/rentals/{rental}', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'show'])
            ->whereNumber('rental');
        Route::post('business/rentals/{rental}/approve', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'approve'])
            ->whereNumber('rental');
        Route::post('business/rentals/{rental}/reject', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'reject'])
            ->whereNumber('rental');
        Route::post('business/rentals/{rental}/condition-report', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'conditionReport'])
            ->whereNumber('rental');
        Route::post('business/rentals/{rental}/mark-picked-up', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'markPickedUp'])
            ->whereNumber('rental');
        Route::post('business/rentals/{rental}/confirm-return', [\App\Http\Controllers\Api\Rentals\Business\RentalsController::class, 'confirmReturn'])
            ->whereNumber('rental');
        Route::get('business/items', [\App\Http\Controllers\Api\Rentals\Business\ItemsController::class, 'index']);
        Route::post('business/items', [\App\Http\Controllers\Api\Rentals\Business\ItemsController::class, 'store']);
        // POST included so multipart updates can use _method=PATCH (some proxies strip PATCH bodies).
        Route::match(['patch', 'post'], 'business/items/{item}', [\App\Http\Controllers\Api\Rentals\Business\ItemsController::class, 'update'])
            ->whereNumber('item');
        Route::get('business/withdrawals', [\App\Http\Controllers\Api\Rentals\Business\WithdrawalsController::class, 'index']);
        Route::post('business/withdrawals', [\App\Http\Controllers\Api\Rentals\Business\WithdrawalsController::class, 'store']);
        Route::get('business/withdrawal-accounts', [\App\Http\Controllers\Api\Rentals\Business\WithdrawalAccountsController::class, 'index']);
        Route::post('business/withdrawal-accounts', [\App\Http\Controllers\Api\Rentals\Business\WithdrawalAccountsController::class, 'store']);
        Route::get('business/settings', [\App\Http\Controllers\Api\Rentals\Business\SettingsController::class, 'show']);
        Route::patch('business/settings', [\App\Http\Controllers\Api\Rentals\Business\SettingsController::class, 'update']);
        Route::post('business/settings/withdrawal-pin', [\App\Http\Controllers\Api\Rentals\Business\SettingsController::class, 'setWithdrawalPin']);
        Route::post('business/settings/withdrawal-pin/verify', [\App\Http\Controllers\Api\Rentals\Business\SettingsController::class, 'verifyWithdrawalPin']);
    });

/**
 * Rentals platform admin API (CheckoutPay admin Sanctum token)
 */
Route::prefix('v1/rentals/admin')->group(function () {
    Route::post('login', [\App\Http\Controllers\Api\Rentals\RentalsAdminAuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'rentals_admin_api', 'touch.rentals.admin.app.session'])->group(function () {
        Route::post('logout', [\App\Http\Controllers\Api\Rentals\RentalsAdminAuthController::class, 'logout']);
        Route::post('auth/session/end', [\App\Http\Controllers\Api\Rentals\RentalsAdminAuthController::class, 'endAppSession']);
        Route::get('me', [\App\Http\Controllers\Api\Rentals\RentalsAdminAuthController::class, 'me']);
        Route::get('kyc-queue', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'kycQueue']);
        Route::post('kyc/{userId}/approve', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'approveKyc'])
            ->whereNumber('userId');
        Route::post('kyc/{userId}/reject', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'rejectKyc'])
            ->whereNumber('userId');
        Route::get('vendor-applications', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'vendorApplications']);
        Route::post('vendor-applications/{id}/approve', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'approveVendorApplication'])
            ->whereNumber('id');
        Route::post('vendor-applications/{id}/reject', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'rejectVendorApplication'])
            ->whereNumber('id');
        Route::post('rentals/{id}/force-complete', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'forceComplete'])
            ->whereNumber('id');
        Route::post('rentals/{id}/force-cancel', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'forceCancel'])
            ->whereNumber('id');
        Route::post('rentals/{id}/refund', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'refund'])
            ->whereNumber('id');
        Route::get('payouts', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'payouts']);
        Route::post('payouts/{id}/hold', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'holdPayout'])
            ->whereNumber('id');
        Route::get('featured', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'featuredItems']);
        Route::get('featured-banners', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'featuredBannersIndex']);
        Route::post('featured-banners', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'featuredBannersStore']);
        Route::patch('featured-banners/{banner}', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'featuredBannersUpdate'])
            ->whereNumber('banner');
        Route::delete('featured-banners/{banner}', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'featuredBannersDestroy'])
            ->whereNumber('banner');
        Route::patch('items/{item}', [\App\Http\Controllers\Api\Rentals\Admin\RentalsAdminController::class, 'updateItem'])
            ->whereNumber('item');
    });
});

/**
 * NigTax admin API (Sanctum token; same Admin accounts as checkout, roles: tax, super_admin)
 */
Route::prefix('v1/tax-admin')->group(function () {
    Route::get('/login', function () {
        return response()->json([
            'message' => 'Use POST with JSON body: {"email":"...","password":"..."}. Roles allowed: tax, super_admin.',
        ]);
    });
    Route::post('/login', [\App\Http\Controllers\Api\TaxAdminAuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', 'tax_admin_api'])->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Api\TaxAdminAuthController::class, 'logout']);
        Route::get('/user', [\App\Http\Controllers\Api\TaxAdminAuthController::class, 'user']);
        Route::get('/stats', [\App\Http\Controllers\Api\TaxAdminStatsController::class, 'index']);
        Route::put('/password', [\App\Http\Controllers\Api\TaxAdminAuthController::class, 'changePassword']);
        Route::get('/business-records', [\App\Http\Controllers\Api\TaxAdminRecordController::class, 'businessRecords']);
        Route::get('/personal-records', [\App\Http\Controllers\Api\TaxAdminRecordController::class, 'personalRecords']);
        Route::get('/pro-users', [\App\Http\Controllers\Api\TaxAdminRecordController::class, 'proUsers']);

        Route::get('/certified/settings', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'certifiedSettings']);
        Route::put('/certified/settings', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'certifiedSettingsUpdate']);
        Route::get('/certified/consultants', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantsIndex']);
        Route::post('/certified/consultants', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantsStore']);
        Route::put('/certified/consultants/{id}', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantsUpdate'])
            ->whereNumber('id');
        Route::delete('/certified/consultants/{id}', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantsDestroy'])
            ->whereNumber('id');
        Route::post('/certified/consultants/{id}/signature', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'uploadConsultantSignature'])
            ->whereNumber('id');
        Route::post('/certified/consultants/{id}/stamp', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'uploadConsultantStamp'])
            ->whereNumber('id');
        Route::get('/certified/consultant', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantShow']);
        Route::put('/certified/consultant', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'consultantUpdate']);
        Route::post('/certified/consultant/signature', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'uploadSignature']);
        Route::post('/certified/consultant/stamp', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'uploadStamp']);
        Route::get('/certified/orders', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'ordersIndex']);
        Route::get('/certified/orders/{id}', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'orderShow'])
            ->whereNumber('id');
        Route::patch('/certified/orders/{id}', [\App\Http\Controllers\Api\TaxAdminCertifiedController::class, 'orderUpdate'])
            ->whereNumber('id');
    });
});

/*
| Tagine ↔ Checkout bridge (shared secret). Tagine generates OTP locally; Checkout only sends WhatsApp + stores wallet.
*/
Route::middleware(['throttle:12,1', 'tagine.otp.secret'])
    ->prefix('tagine')
    ->group(function () {
        Route::post('whatsapp/send-text', [TagineBridgeController::class, 'sendWhatsAppText']);
        Route::post('wallet/ensure', [TagineBridgeController::class, 'ensureWallet']);
    });

/*
| Amithy DRM desktop telemetry + policy webhook (HMAC + bearer)
| Bearer maps to either a desktop_app_tokens row or env AMITHY_API_TOKEN.
*/
Route::prefix('desktop')->middleware('throttle:120,1')->group(function () {
    Route::post('/events/batch', [\App\Http\Controllers\Api\DesktopTelemetryController::class, 'ingestBatch']);
    Route::get('/policy', [\App\Http\Controllers\Api\DesktopTelemetryController::class, 'getPolicy']);
});

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => \App\Support\SiteBranding::name(),
    ]);
});
