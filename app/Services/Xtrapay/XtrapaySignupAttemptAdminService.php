<?php

namespace App\Services\Xtrapay;

use App\Models\Wallet;
use App\Support\Xtrapay\XtrapayWalletApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin visibility into incomplete Xtrapay app signups (cache-backed) and OTP email holds.
 */
final class XtrapaySignupAttemptAdminService
{
    /** @var list<string> */
    private const EMAIL_HOLD_PURPOSES = ['login', 'register', 'reset', 'pin_change', 'pin_set', 'pin_forgot'];

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenAttempts(int $limit = 40): array
    {
        if (! Schema::hasTable('cache') || config('cache.default') !== 'database') {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $now = time();

        $rows = DB::table('cache')
            ->where('key', 'like', 'xtrapay:reg:%')
            ->where('key', 'not like', 'xtrapay:reg-lookup:%')
            ->where('expiration', '>', $now)
            ->orderByDesc('expiration')
            ->limit($limit)
            ->get(['key', 'expiration']);

        $out = [];
        foreach ($rows as $row) {
            $id = str_replace('xtrapay:reg:', '', (string) $row->key);
            if ($id === '' || ! str_contains((string) $row->key, 'xtrapay:reg:')) {
                continue;
            }
            if (str_starts_with($id, 'lookup:')) {
                continue;
            }

            $reg = Cache::get('xtrapay:reg:'.$id);
            if (! is_array($reg)) {
                continue;
            }

            $out[] = $this->decorateAttempt($id, $reg, (int) $row->expiration);
        }

        usort($out, static function (array $a, array $b): int {
            $stuckCmp = ((int) ($b['is_stuck'] ?? false)) <=> ((int) ($a['is_stuck'] ?? false));
            if ($stuckCmp !== 0) {
                return $stuckCmp;
            }

            return ((int) ($b['started_at_ts'] ?? 0)) <=> ((int) ($a['started_at_ts'] ?? 0));
        });

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function emailHoldStatus(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $email,
                'held' => false,
                'holds' => [],
                'clearest_expires_at' => null,
                'seconds_remaining' => 0,
            ];
        }

        $holds = [];
        $maxExp = null;
        foreach (self::EMAIL_HOLD_PURPOSES as $purpose) {
            $key = $this->emailSentKey($email, $purpose);
            $exp = $this->cacheExpiration($key);
            if ($exp === null) {
                continue;
            }
            $cached = Cache::get($key);
            $holds[] = [
                'purpose' => $purpose,
                'expires_at' => Carbon::createFromTimestamp($exp)->toDateTimeString(),
                'seconds_remaining' => max(0, $exp - time()),
                'code_present' => is_string($cached) && $cached !== '',
            ];
            $maxExp = $maxExp === null ? $exp : max($maxExp, $exp);
        }

        $seconds = $maxExp !== null ? max(0, $maxExp - time()) : 0;

        return [
            'email' => $email,
            'held' => $holds !== [],
            'holds' => $holds,
            'clearest_expires_at' => $maxExp !== null
                ? Carbon::createFromTimestamp($maxExp)->toDateTimeString()
                : null,
            'seconds_remaining' => $seconds,
        ];
    }

    /**
     * Clears the OTP email dedupe hold so the user can receive a fresh code immediately.
     *
     * @return array{email: string, cleared_keys: list<string>, cleared_count: int}
     */
    public function clearEmailHold(string $email): array
    {
        $email = strtolower(trim($email));
        $cleared = [];

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $email,
                'cleared_keys' => [],
                'cleared_count' => 0,
            ];
        }

        foreach (self::EMAIL_HOLD_PURPOSES as $purpose) {
            $key = $this->emailSentKey($email, $purpose);
            if (Cache::has($key)) {
                Cache::forget($key);
                $cleared[] = $key;
            }
        }

        // Legacy / mismatched verify lookup key (no purpose suffix).
        $bare = 'xtrapay:otp-email-sent:'.sha1($email);
        if (Cache::has($bare)) {
            Cache::forget($bare);
            $cleared[] = $bare;
        }

        $lockKey = 'xtrapay:otp-register-mail:'.sha1($email);
        if (Cache::has($lockKey)) {
            Cache::forget($lockKey);
            $cleared[] = $lockKey;
        }

        return [
            'email' => $email,
            'cleared_keys' => $cleared,
            'cleared_count' => count($cleared),
        ];
    }

    /**
     * @param  array<string, mixed>  $reg
     * @return array<string, mixed>
     */
    private function decorateAttempt(string $id, array $reg, int $expirationTs): array
    {
        $email = strtolower(trim((string) ($reg['email'] ?? '')));
        $phoneE164 = (string) ($reg['phone_e164'] ?? '');
        if ($phoneE164 === '' && ! empty($reg['phone'])) {
            $phoneE164 = (string) (XtrapayWalletApi::normalizePhone((string) $reg['phone']) ?? '');
        }

        $hold = $this->emailHoldStatus($email);
        $hasRegisterOtp = $this->hasOtpFor($email, $phoneE164, 'register');
        $walletByPhone = $phoneE164 !== '' ? Wallet::findByPhoneE164($phoneE164) : null;
        $walletByEmail = $email !== ''
            ? Wallet::query()->whereKycEmail($email)->first()
            : null;

        $reasons = [];
        if ($walletByPhone || $walletByEmail) {
            $reasons[] = 'A wallet already exists for this phone/email — signup may be stuck on an old attempt. Have them log in instead.';
        }
        if ($hold['held']) {
            $mins = max(1, (int) ceil(($hold['seconds_remaining'] ?? 0) / 60));
            $reasons[] = "Email OTP hold active (~{$mins} min left). Resend will claim success but no new email is sent until the hold clears.";
        }
        if (! $hasRegisterOtp && ! ($walletByPhone || $walletByEmail)) {
            if ($hold['held']) {
                $reasons[] = 'No active register OTP in cache, and email hold is blocking a new send. Clear the email hold so they can request a code again.';
            } else {
                $reasons[] = 'No active register OTP in cache (expired or never sent). Ask them to tap Resend / request OTP again.';
            }
        }
        if ($hasRegisterOtp && ! $hold['held']) {
            $reasons[] = 'OTP is in cache — check spam/junk for the verification email, or confirm they enter the latest code.';
        }
        if ($reasons === []) {
            $reasons[] = 'Incomplete signup (wallet not created yet). Waiting for OTP verify.';
        }

        $isStuck = $hold['held'] || (! $hasRegisterOtp && ! ($walletByPhone || $walletByEmail));
        // Registration cache TTL is 1 day; approximate started_at from expiration.
        $startedAtTs = $expirationTs - 86400;

        return [
            'registration_id' => $id,
            'full_name' => (string) ($reg['full_name'] ?? ''),
            'email' => $email,
            'phone' => (string) ($reg['phone'] ?? ''),
            'phone_e164' => $phoneE164,
            'status' => (string) ($reg['status'] ?? 'basic'),
            'expires_at' => Carbon::createFromTimestamp($expirationTs)->toDateTimeString(),
            'started_at' => Carbon::createFromTimestamp($startedAtTs)->toDateTimeString(),
            'started_at_ts' => $startedAtTs,
            'has_register_otp' => $hasRegisterOtp,
            'email_hold' => $hold,
            'wallet_id' => $walletByPhone?->id ?? $walletByEmail?->id,
            'stuck_reasons' => $reasons,
            'is_stuck' => $isStuck,
        ];
    }

    private function hasOtpFor(string $email, string $phoneE164, string $purpose): bool
    {
        foreach (array_filter([$email, $phoneE164]) as $dest) {
            $key = 'xtrapay:otp:'.sha1(strtolower(trim($dest)).'|'.$purpose);
            $stored = Cache::get($key);
            if (is_string($stored) && preg_match('/^\d{4,8}$/', $stored) === 1) {
                return true;
            }
        }

        foreach (array_filter([$phoneE164, $email]) as $scope) {
            $active = Cache::get('xtrapay:otp-active:'.$purpose.':'.sha1(strtolower(trim($scope))));
            if (is_string($active) && preg_match('/^\d{4,8}$/', $active) === 1) {
                return true;
            }
        }

        return false;
    }

    private function emailSentKey(string $email, string $purpose): string
    {
        return 'xtrapay:otp-email-sent:'.sha1(strtolower(trim($email)).'|'.$purpose);
    }

    private function cacheExpiration(string $key): ?int
    {
        if (! Cache::has($key)) {
            return null;
        }

        if (Schema::hasTable('cache') && config('cache.default') === 'database') {
            $exp = DB::table('cache')->where('key', $key)->value('expiration');

            return $exp !== null ? (int) $exp : null;
        }

        return time() + 60;
    }
}
