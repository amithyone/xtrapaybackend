<?php

namespace App\Services\Consumer;

use App\Models\ConsumerDeviceStepupSession;
use App\Models\ConsumerWalletApiAccount;
use App\Models\Wallet;
use App\Services\Whatsapp\PhoneNormalizer;
use App\Services\Whatsapp\WhatsappWalletPinResetService;
use Illuminate\Support\Str;

class ConsumerDeviceStepupService
{
    public function __construct(
        private ConsumerWalletOtpService $otp,
        private ConsumerWalletPinVerifier $pinVerifier,
        private WhatsappWalletPinResetService $pinReset,
        private ConsumerDeviceTrustService $trust,
        private ConsumerDeviceStepupPushService $stepupPush,
    ) {}

    /**
     * @return array{ok: bool, message?: string, stepup_required?: bool, stepup_session?: string, stepup_mode?: string, other_device_label?: string|null, channels?: string[], pin_reset_required?: bool, next_step?: string, email_masked?: string|null}
     */
    public function start(
        string $phoneInput,
        ?string $pin = null,
        ?string $otpCode = null,
        ?string $deviceId = null,
        ?string $platform = null,
        ?string $deviceLabel = null,
    ): array {
        if (! $this->trust->isEnabled()) {
            return ['ok' => false, 'message' => 'Device trust is disabled.'];
        }

        $e164 = PhoneNormalizer::canonicalAuthE164Digits($phoneInput);
        if ($e164 === null) {
            return ['ok' => false, 'message' => 'Invalid mobile number for a supported country.'];
        }

        $wallet = Wallet::query()->where('phone_e164', $e164)->first();
        if (! $wallet || $wallet->needsRegistrationProfile()) {
            return ['ok' => false, 'message' => 'Complete registration to create your wallet.'];
        }

        if ($pin === null && $otpCode === null) {
            return ['ok' => false, 'message' => 'Provide PIN or OTP code.'];
        }

        if ($pin !== null) {
            $auth = $this->verifyPin($wallet, $pin);
        } else {
            $auth = $this->verifyOtpCode($phoneInput, (string) $otpCode);
        }

        if (! $auth['ok']) {
            return $auth;
        }

        $account = ConsumerWalletApiAccount::query()->firstOrNew(['phone_e164' => $e164]);
        $account->wallet_id = $wallet->id;
        $account->phone_e164 = $e164;
        $account->save();

        if (! $this->trust->requiresStepUp($account, $deviceId)) {
            return [
                'ok' => true,
                'stepup_required' => false,
                'message' => 'No step-up required.',
            ];
        }

        $session = $this->createSession($account, $wallet, $deviceId, $platform, $deviceLabel);

        return array_merge(['ok' => true], $this->trust->stepUpPayload($session, $wallet));
    }

    /**
     * @return array{ok: bool, message?: string, bvn_verified?: bool}
     */
    public function verifyBvn(string $sessionToken, string $bvn): array
    {
        $session = $this->findActiveSession($sessionToken);
        if ($session === null) {
            return ['ok' => false, 'message' => 'Step-up session expired.'];
        }

        if ($this->trust->isFirstDeviceEmailStepUp($session->account)) {
            return ['ok' => false, 'message' => 'BVN is not required. Enter the email OTP we sent.'];
        }

        $wallet = $session->wallet;
        if (! $wallet || ! $this->pinReset->verifyBvn($wallet, $bvn)) {
            return ['ok' => false, 'message' => 'BVN/NIN does not match our records.'];
        }

        $session->bvn_verified_at = now();
        $session->save();

        return ['ok' => true, 'bvn_verified' => true];
    }

    /**
     * @return array{ok: bool, message?: string, sent?: bool, channel?: string, email_masked?: string|null}
     */
    public function requestOtp(string $sessionToken, string $channel): array
    {
        $session = $this->findActiveSession($sessionToken);
        if ($session === null) {
            return ['ok' => false, 'message' => 'Step-up session expired.'];
        }

        $firstDevice = $this->trust->isFirstDeviceEmailStepUp($session->account);
        if ($firstDevice) {
            $channel = 'email';
        } elseif ($session->bvn_verified_at === null) {
            return ['ok' => false, 'message' => 'Verify BVN/NIN first.'];
        }

        $result = $this->otp->requestOtp(
            (string) $session->phone_e164,
            $channel,
            null,
            null,
            forDeviceTrust: $firstDevice,
        );
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        return [
            'ok' => true,
            'sent' => true,
            'channel' => $result['channel'] ?? $channel,
            'email_masked' => $result['email_masked'] ?? null,
        ];
    }

    /**
     * @return array{ok: bool, message?: string, stepup_token?: string, pin_reset_required?: bool, next_step?: string, token?: string, token_type?: string, phone_e164?: string, wallet_id?: int, trusted_device_id?: int, stepup_mode?: string}
     */
    public function verifyOtp(string $sessionToken, string $code): array
    {
        $session = $this->findActiveSession($sessionToken);
        if ($session === null) {
            return ['ok' => false, 'message' => 'Step-up session expired.'];
        }

        $firstDevice = $this->trust->isFirstDeviceEmailStepUp($session->account);
        if (! $firstDevice && $session->bvn_verified_at === null) {
            return ['ok' => false, 'message' => 'Verify BVN/NIN first.'];
        }

        $verified = $this->otp->verifyOtp((string) $session->phone_e164, $code);
        if (! $verified['ok']) {
            return ['ok' => false, 'message' => $verified['message']];
        }

        $session->otp_verified_at = now();
        $session->save();

        if ($firstDevice) {
            $bound = $this->trust->bindFirstDeviceAfterEmailOtp(
                $session,
                $session->pending_device_id,
                $session->pending_platform,
                $session->pending_device_label,
            );
            if (! ($bound['ok'] ?? false)) {
                return ['ok' => false, 'message' => $bound['message'] ?? 'Could not trust this device.'];
            }

            return [
                'ok' => true,
                'stepup_mode' => 'first_device_email',
                'token' => $bound['token'],
                'token_type' => 'Bearer',
                'phone_e164' => $bound['phone_e164'] ?? null,
                'wallet_id' => $bound['wallet_id'] ?? null,
                'trusted_device_id' => $bound['trusted_device_id'] ?? null,
                'pin_reset_required' => false,
                'next_step' => 'done',
            ];
        }

        $token = 'bind_'.Str::random(48);
        $session->stepup_token = $token;
        $session->stepup_token_expires_at = now()->addMinutes(15);
        $session->save();

        return [
            'ok' => true,
            'stepup_mode' => 'device_mismatch',
            'stepup_token' => $token,
            'pin_reset_required' => true,
            'next_step' => 'set_new_pin_and_bind',
        ];
    }

    public function findSessionByStepupToken(string $token): ?ConsumerDeviceStepupSession
    {
        $session = ConsumerDeviceStepupSession::query()
            ->where('stepup_token', $token)
            ->first();

        if ($session === null || ! $session->isStepupTokenValid($token)) {
            return null;
        }

        return $session;
    }

    public function createSession(
        ConsumerWalletApiAccount $account,
        Wallet $wallet,
        ?string $pendingDeviceId = null,
        ?string $pendingPlatform = null,
        ?string $pendingDeviceLabel = null,
    ): ConsumerDeviceStepupSession {
        ConsumerDeviceStepupSession::query()
            ->where('consumer_wallet_api_account_id', $account->id)
            ->where('expires_at', '>', now())
            ->delete();

        $firstDevice = $this->trust->isFirstDeviceEmailStepUp($account);
        if (! $firstDevice) {
            $account->forceFill(['pin_reset_required' => true])->save();
        }

        $session = ConsumerDeviceStepupSession::query()->create([
            'session_token' => 'sess_'.Str::random(40),
            'consumer_wallet_api_account_id' => $account->id,
            'phone_e164' => (string) $account->phone_e164,
            'wallet_id' => $wallet->id,
            'pending_device_id' => $this->trust->normalizeDeviceId($pendingDeviceId),
            'pending_platform' => $pendingPlatform,
            'pending_device_label' => $pendingDeviceLabel,
            'auth_verified_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        if ($firstDevice) {
            $this->otp->requestOtp(
                (string) $account->phone_e164,
                'email',
                null,
                null,
                forDeviceTrust: true,
            );
        }

        return $session;
    }

    private function findActiveSession(string $sessionToken): ?ConsumerDeviceStepupSession
    {
        $session = ConsumerDeviceStepupSession::query()
            ->where('session_token', $sessionToken)
            ->first();

        if ($session === null || $session->isExpired()) {
            return null;
        }

        return $session;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private function verifyPin(Wallet $wallet, string $pin): array
    {
        if ($wallet->isPinLocked()) {
            return ['ok' => false, 'message' => 'Wallet PIN is locked. Try again later or use WhatsApp OTP.'];
        }

        if (! $wallet->hasPin()) {
            return ['ok' => false, 'message' => 'PIN is not set yet. Sign in with OTP first.'];
        }

        if (! $this->pinVerifier->verify($wallet, $pin)) {
            $wallet->increment('pin_failed_attempts');
            $wallet->refresh();
            if ((int) $wallet->pin_failed_attempts >= 5) {
                $wallet->pin_locked_until = now()->addMinutes(15);
                $wallet->save();

                return ['ok' => false, 'message' => 'Too many wrong PIN attempts. Wallet PIN locked for 15 minutes.'];
            }

            return ['ok' => false, 'message' => 'Incorrect wallet PIN.'];
        }

        $wallet->pin_failed_attempts = 0;
        $wallet->pin_locked_until = null;
        $wallet->save();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private function verifyOtpCode(string $phoneInput, string $code): array
    {
        $checked = $this->otp->checkOtp($phoneInput, $code);
        if (! $checked['ok']) {
            return ['ok' => false, 'message' => $checked['message']];
        }

        $verified = $this->otp->verifyOtp($phoneInput, $code);
        if (! $verified['ok']) {
            return ['ok' => false, 'message' => $verified['message']];
        }

        return ['ok' => true];
    }
}
