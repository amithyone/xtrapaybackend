<?php

namespace App\Services\Consumer;

use App\Models\Wallet;
use App\Services\Whatsapp\EvolutionWhatsAppClient;
use App\Services\Whatsapp\PhoneNormalizer;
use App\Services\Whatsapp\WhatsappEvolutionConfigResolver;
use App\Models\WalletSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ConsumerWalletOtpService
{
    private const CACHE_OTP = 'consumer_wallet_otp:';

    private const CACHE_ATTEMPTS = 'consumer_wallet_otp_attempts:';

    private const CACHE_UNUSED_SENDS = 'consumer_wallet_otp_unused_sends:';

    public function __construct(
        private EvolutionWhatsAppClient $whatsapp,
    ) {}

    private function otpKey(string $e164): string
    {
        return self::CACHE_OTP.hash('sha256', $e164);
    }

    private function attemptsKey(string $e164): string
    {
        return self::CACHE_ATTEMPTS.hash('sha256', $e164);
    }

    private function unusedSendsKey(string $e164): string
    {
        return self::CACHE_UNUSED_SENDS.hash('sha256', $e164);
    }

    private function emailSentKey(string $e164, string $code): string
    {
        return 'consumer_wallet_otp_email_sent:'.hash('sha256', $e164.'|'.$code);
    }

    public function isOtpBlocked(string $e164): bool
    {
        return (int) Cache::get($this->unusedSendsKey($e164), 0) >= $this->maxUnusedOtpSends();
    }

    /**
     * Seconds remaining on the unused-OTP lockout (0 if not locked).
     */
    public function otpLockoutRetryAfterSeconds(string $e164): int
    {
        if (! $this->isOtpBlocked($e164)) {
            return 0;
        }

        return $this->lockoutMinutes() * 60;
    }

    public function clearUnusedOtpSends(string $e164): void
    {
        Cache::forget($this->unusedSendsKey($e164));
    }

    /**
     * Admin/support: current OTP lockout state for a wallet phone.
     *
     * @return array{
     *     app_otp_blocked: bool,
     *     unused_otp_sends: int,
     *     unused_otp_sends_max: int,
     *     verify_attempts: int,
     *     verify_attempts_max: int,
     *     verify_locked: bool,
     *     has_pending_app_otp: bool,
     *     whatsapp_session_state: string|null,
     *     whatsapp_otp_attempts: int,
     *     whatsapp_otp_locked: bool,
     *     is_stuck: bool
     * }
     */
    public function lockoutStatusForAdmin(string $e164): array
    {
        $maxUnused = $this->maxUnusedOtpSends();
        $unusedCount = (int) Cache::get($this->unusedSendsKey($e164), 0);
        $verifyAttempts = (int) Cache::get($this->attemptsKey($e164), 0);
        $maxVerify = max(3, (int) config('consumer_wallet.otp_max_attempts', 5));

        $session = null;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('wallet_sessions')) {
                $session = WalletSession::query()->where('phone_e164', $e164)->first();
            }
        } catch (\Throwable) {
            $session = null;
        }
        $whatsappOtpAttempts = (int) ($session?->otp_attempts ?? 0);
        $whatsappOtpMax = max(1, (int) config('whatsapp.otp.max_attempts', 5));
        $whatsappOtpLocked = $session !== null
            && $session->state === WalletSession::STATE_AWAIT_OTP
            && $whatsappOtpAttempts >= $whatsappOtpMax;

        $blocked = $this->isOtpBlocked($e164);
        $verifyLocked = $verifyAttempts >= $maxVerify;

        return [
            'app_otp_blocked' => $blocked,
            'unused_otp_sends' => $unusedCount,
            'unused_otp_sends_max' => $maxUnused,
            'verify_attempts' => $verifyAttempts,
            'verify_attempts_max' => $maxVerify,
            'verify_locked' => $verifyLocked,
            'has_pending_app_otp' => Cache::has($this->otpKey($e164)),
            'whatsapp_session_state' => $session?->state,
            'whatsapp_otp_attempts' => $whatsappOtpAttempts,
            'whatsapp_otp_locked' => $whatsappOtpLocked,
            'is_stuck' => $blocked || $verifyLocked || $whatsappOtpLocked,
        ];
    }

    /**
     * Admin/support: clear app OTP caches and WhatsApp email-link OTP attempt counters.
     *
     * @return array{cleared_unused_sends: bool, cleared_verify_attempts: bool, cleared_pending_otp: bool, cleared_whatsapp_session_otp: bool}
     */
    public function clearAllLockouts(string $e164): array
    {
        $hadUnused = Cache::has($this->unusedSendsKey($e164));
        $hadAttempts = Cache::has($this->attemptsKey($e164));
        $hadPending = Cache::has($this->otpKey($e164));

        Cache::forget($this->unusedSendsKey($e164));
        Cache::forget($this->attemptsKey($e164));
        Cache::forget($this->otpKey($e164));

        $clearedSession = false;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('wallet_sessions')) {
                $session = WalletSession::query()->where('phone_e164', $e164)->first();
                if ($session !== null && (int) $session->otp_attempts > 0) {
                    $session->update(['otp_attempts' => 0]);
                    $clearedSession = true;
                }
            }
        } catch (\Throwable) {
            $clearedSession = false;
        }

        return [
            'cleared_unused_sends' => $hadUnused,
            'cleared_verify_attempts' => $hadAttempts,
            'cleared_pending_otp' => $hadPending,
            'cleared_whatsapp_session_otp' => $clearedSession,
        ];
    }

    private function maxUnusedOtpSends(): int
    {
        return max(2, min(10, (int) config('consumer_wallet.otp_max_unused_sends', 3)));
    }

    private function lockoutMinutes(): int
    {
        return max(1, min(60, (int) config('consumer_wallet.otp_lockout_minutes', 10)));
    }

    private function recordUnusedOtpSend(string $e164): void
    {
        $key = $this->unusedSendsKey($e164);
        $n = (int) Cache::get($key, 0);
        Cache::put($key, $n + 1, now()->addMinutes($this->lockoutMinutes()));
    }

    /**
     * @return array{ok: false, message: string, otp_blocked: true, retry_after_seconds: int, lockout_minutes: int}
     */
    private function otpBlockedPayload(string $e164): array
    {
        $minutes = $this->lockoutMinutes();
        $retryAfter = max(60, $this->otpLockoutRetryAfterSeconds($e164) ?: ($minutes * 60));

        return [
            'ok' => false,
            'message' => "Too many unused login codes. Please wait {$minutes} minutes, then request a new code.",
            'otp_blocked' => true,
            'retry_after_seconds' => $retryAfter,
            'lockout_minutes' => $minutes,
        ];
    }

    /**
     * @return array{ok: bool, message: string, whatsapp?: bool, email?: bool, email_masked?: string|null}
     */
    public function otpOptions(string $phoneInput, ?string $countryIso = null): array
    {
        $e164 = Wallet::resolveAuthE164($phoneInput, $countryIso);
        if ($e164 === null) {
            return ['ok' => false, 'message' => 'Invalid mobile number for a supported country.'];
        }

        $wallet = Wallet::findByPhoneE164($e164);
        $email = $wallet?->resolveOtpEmail();
        $emailEligible = $wallet?->isTier2() === true && $email !== null;
        $otpBlocked = $this->isOtpBlocked($e164);
        $walletExists = $wallet !== null;
        $needsRegistration = $wallet === null || $wallet->needsRegistrationProfile();

        $payload = [
            'ok' => true,
            'message' => 'OK',
            'whatsapp' => ! $otpBlocked,
            'email' => ($emailEligible || $needsRegistration) && ! $otpBlocked,
            'email_masked' => $emailEligible ? $this->maskEmail($email) : null,
            'otp_blocked' => $otpBlocked,
            'has_pin' => $wallet?->hasPin() ?? false,
            'wallet_exists' => $walletExists,
            'needs_registration' => $needsRegistration,
        ];

        if ($otpBlocked) {
            $minutes = $this->lockoutMinutes();
            $payload['message'] = "Too many unused login codes. Please wait {$minutes} minutes, then request a new code.";
            $payload['retry_after_seconds'] = max(60, $this->otpLockoutRetryAfterSeconds($e164) ?: ($minutes * 60));
            $payload['lockout_minutes'] = $minutes;
        }

        return $payload;
    }

    /**
     * @return array{ok: bool, message: string, channel?: string, otp_blocked?: bool, email_masked?: string|null, fallback_from_whatsapp?: bool}
     */
    public function requestOtp(string $phoneInput, string $channel = 'whatsapp', ?string $registrationEmail = null, ?string $countryIso = null, bool $forDeviceTrust = false): array
    {
        $e164 = Wallet::resolveAuthE164($phoneInput, $countryIso);
        if ($e164 === null) {
            return ['ok' => false, 'message' => 'Invalid mobile number for a supported country.'];
        }

        $existing = Wallet::findByPhoneE164($e164);
        if ($existing?->isLockedDown()) {
            return ['ok' => false, 'message' => Wallet::lockdownMessage()];
        }

        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['whatsapp', 'email'], true)) {
            return ['ok' => false, 'message' => 'Invalid delivery channel.'];
        }

        if ($this->isOtpBlocked($e164)) {
            return $this->otpBlockedPayload($e164);
        }

        $ttl = max(60, (int) config('consumer_wallet.otp_ttl_seconds', 600));
        $len = max(4, min(8, (int) config('consumer_wallet.otp_length', 6)));
        $maxDigits = 10 ** $len - 1;
        $pending = Cache::get($this->otpKey($e164));
        $reuse = is_array($pending)
            && is_string($pending['code'] ?? null)
            && preg_match('/^\d{4,8}$/', (string) $pending['code']) === 1
            && (int) ($pending['expires_at'] ?? 0) > time();
        $code = $reuse
            ? (string) $pending['code']
            : str_pad((string) random_int(0, $maxDigits), $len, '0', STR_PAD_LEFT);

        Cache::put($this->otpKey($e164), [
            'code_hash' => hash('sha256', $code),
            'code' => $code,
            'expires_at' => $reuse
                ? (int) $pending['expires_at']
                : now()->addSeconds($ttl)->timestamp,
        ], $ttl);

        if ($channel === 'email') {
            if ($reuse && Cache::has($this->emailSentKey($e164, $code))) {
                return [
                    'ok' => true,
                    'message' => 'OTP sent to your email.',
                    'channel' => 'email',
                ];
            }

            return $this->deliverEmailOtp($e164, $code, $ttl, $registrationEmail, false, $forDeviceTrust);
        }

        $instance = WhatsappEvolutionConfigResolver::walletInstanceForPhone($e164);
        $brand = (string) config('whatsapp.bot_brand_name', 'Checkout');
        $text = "*{$brand}* app login\n\nYour code: *{$code}*\n\nIt expires in ".round($ttl / 60).' minutes. Do not share this code.';

        $sent = false;
        if ($instance !== '') {
            // Template works without a 24h session. Session text is fallback only —
            // sending both at once delivered the same OTP twice on WhatsApp.
            $templateSent = $this->whatsapp->sendAuthenticationOtp($instance, $e164, $code);
            $textSent = false;
            if (! $templateSent) {
                $textSent = $this->whatsapp->sendText($instance, $e164, $text);
            }
            $sent = $templateSent || $textSent;
            Log::info('consumer_wallet.otp: whatsapp delivery', [
                'phone_e164' => $e164,
                'template_sent' => $templateSent,
                'text_sent' => $textSent,
            ]);
        } else {
            Log::warning('consumer_wallet.otp: no whatsapp instance', ['phone_e164' => $e164]);
        }

        if ($sent) {
            if (! $reuse) {
                Cache::forget($this->attemptsKey($e164));
                $this->recordUnusedOtpSend($e164);
            }

            return ['ok' => true, 'message' => 'OTP sent to your WhatsApp.', 'channel' => 'whatsapp'];
        }

        Log::warning('consumer_wallet.otp: whatsapp send failed, trying email fallback', [
            'phone_e164' => $e164,
            'has_instance' => $instance !== '',
        ]);

        return $this->deliverEmailOtp($e164, $code, $ttl, $registrationEmail, true, $forDeviceTrust);
    }

    /**
     * @return array{ok: bool, message: string, channel?: string, email_masked?: string|null, fallback_from_whatsapp?: bool}
     */
    private function deliverEmailOtp(
        string $e164,
        string $code,
        int $ttl,
        ?string $registrationEmail,
        bool $fromWhatsappFallback,
        bool $forDeviceTrust = false,
    ): array {
        $wallet = Wallet::findByPhoneE164($e164);
        $email = $wallet?->resolveOtpEmail();
        $needsRegistration = $wallet === null || $wallet->needsRegistrationProfile();

        if ($fromWhatsappFallback) {
            // Fallback: any stored KYC/renter email, or registration email if provided.
            $registrationEmail = strtolower(trim((string) $registrationEmail));
            if (($email === null || $email === '') && $registrationEmail !== '' && filter_var($registrationEmail, FILTER_VALIDATE_EMAIL)) {
                $email = $registrationEmail;
            }
            if ($email === null || $email === '') {
                Cache::forget($this->otpKey($e164));

                return [
                    'ok' => false,
                    'message' => 'WhatsApp could not deliver your code. Request OTP by email (enter your email if signing up), or try again later.',
                    'fallback_from_whatsapp' => true,
                ];
            }
        } elseif ($needsRegistration) {
            $registrationEmail = strtolower(trim((string) $registrationEmail));
            if ($registrationEmail === '' || ! filter_var($registrationEmail, FILTER_VALIDATE_EMAIL)) {
                Cache::forget($this->otpKey($e164));

                return ['ok' => false, 'message' => 'Enter a valid email address to receive your code.'];
            }
            $email = $registrationEmail;
        } elseif ($forDeviceTrust) {
            if ($email === null || $email === '') {
                Cache::forget($this->otpKey($e164));

                return [
                    'ok' => false,
                    'message' => 'No email on this wallet. Add a KYC email with support, then try again to trust this device.',
                ];
            }
        } elseif (! $wallet?->isTier2() || $email === null) {
            Cache::forget($this->otpKey($e164));

            return ['ok' => false, 'message' => 'Email OTP is only available for verified Tier 2 wallets with a KYC email.'];
        }

        try {
            $brand = (string) config('whatsapp.bot_brand_name', 'Checkout');
            $subject = $forDeviceTrust
                ? "Your {$brand} device verification code"
                : "Your {$brand} app login code";
            Mail::send('emails.login-otp-code', [
                'code' => $code,
                'ttlMinutes' => max(1, (int) round($ttl / 60)),
                'heading' => $forDeviceTrust ? 'Your device verification code' : null,
                'intro' => $forDeviceTrust
                    ? 'Use this code to trust this device for your wallet. It expires in '.max(1, (int) round($ttl / 60)).' minutes.'
                    : null,
            ], function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Cache::forget($this->otpKey($e164));
            Log::warning('consumer_wallet.otp: email send failed', [
                'error' => $e->getMessage(),
                'fallback_from_whatsapp' => $fromWhatsappFallback,
                'for_device_trust' => $forDeviceTrust,
            ]);

            return [
                'ok' => false,
                'message' => $fromWhatsappFallback
                    ? 'WhatsApp and email both failed to send your code. Try again later.'
                    : 'Could not send OTP email. Try WhatsApp instead.',
                'fallback_from_whatsapp' => $fromWhatsappFallback ?: null,
            ];
        }

        Cache::forget($this->attemptsKey($e164));
        $this->recordUnusedOtpSend($e164);
        Cache::put($this->emailSentKey($e164, $code), 1, 45);

        if ($fromWhatsappFallback) {
            return [
                'ok' => true,
                'message' => 'WhatsApp was unavailable, so we emailed your code to '.$this->maskEmail($email).'.',
                'channel' => 'email',
                'email_masked' => $this->maskEmail($email),
                'fallback_from_whatsapp' => true,
            ];
        }

        return [
            'ok' => true,
            'message' => $forDeviceTrust
                ? 'Device verification code sent to '.$this->maskEmail($email).'.'
                : ($needsRegistration ? 'OTP sent to your email.' : 'OTP sent to your KYC email.'),
            'channel' => 'email',
            'email_masked' => $this->maskEmail($email),
        ];
    }

    private function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $visible = substr($local, 0, 1);
        $maskedLocal = strlen($local) <= 2 ? $visible.'*' : $visible.str_repeat('*', min(6, strlen($local) - 1));

        return $maskedLocal.'@'.$domain;
    }

    /**
     * Validate OTP without consuming it (for registration hand-off).
     *
     * @return array{ok: bool, message: string, phone_e164?: string}
     */
    public function checkOtp(string $phoneInput, string $code, ?string $countryIso = null): array
    {
        $e164 = Wallet::resolveAuthE164($phoneInput, $countryIso);
        if ($e164 === null) {
            return ['ok' => false, 'message' => 'Invalid mobile number for a supported country.'];
        }

        $attemptsKey = $this->attemptsKey($e164);
        $attempts = (int) Cache::get($attemptsKey, 0);
        $maxAttempts = max(3, (int) config('consumer_wallet.otp_max_attempts', 5));
        $attemptTtl = $this->lockoutMinutes() * 60;
        if ($attempts >= $maxAttempts) {
            $minutes = $this->lockoutMinutes();

            return [
                'ok' => false,
                'message' => "Too many wrong codes. Wait {$minutes} minutes, then request a new code.",
                'retry_after_seconds' => $attemptTtl,
                'lockout_minutes' => $minutes,
            ];
        }

        $payload = Cache::get($this->otpKey($e164));
        if (! is_array($payload) || empty($payload['code_hash'])) {
            Cache::put($attemptsKey, $attempts + 1, $attemptTtl);

            return ['ok' => false, 'message' => 'Invalid or expired OTP.'];
        }

        $code = trim($code);
        if ($code === '' || ! hash_equals((string) $payload['code_hash'], hash('sha256', $code))) {
            Cache::put($attemptsKey, $attempts + 1, $attemptTtl);

            return ['ok' => false, 'message' => 'Invalid OTP.'];
        }

        return ['ok' => true, 'message' => 'Valid.', 'phone_e164' => $e164];
    }

    /**
     * @return array{ok: bool, message: string, phone_e164?: string}
     */
    public function verifyOtp(string $phoneInput, string $code, ?string $countryIso = null): array
    {
        $checked = $this->checkOtp($phoneInput, $code, $countryIso);
        if (! $checked['ok']) {
            return $checked;
        }

        $e164 = (string) $checked['phone_e164'];
        Cache::forget($this->otpKey($e164));
        Cache::forget($this->attemptsKey($e164));
        $this->clearUnusedOtpSends($e164);

        return ['ok' => true, 'message' => 'Verified.', 'phone_e164' => $e164];
    }
}
