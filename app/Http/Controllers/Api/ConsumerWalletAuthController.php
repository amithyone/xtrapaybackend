<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumerAppSession;
use App\Models\ConsumerWalletApiAccount;
use App\Models\Wallet;
use App\Services\Consumer\ConsumerAppSessionService;
use App\Services\Consumer\ConsumerWalletOtpService;
use App\Services\Consumer\ConsumerWalletLockdownService;
use App\Services\Consumer\ConsumerWalletPinRecoveryService;
use App\Services\Consumer\ConsumerWalletPinVerifier;
use App\Services\Consumer\ConsumerWalletRegistrationService;
use App\Services\Consumer\ConsumerDeviceStepupService;
use App\Services\Consumer\ConsumerDeviceTrustService;
use App\Services\Whatsapp\PhoneNormalizer;
use App\Services\Region\RegionCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumerWalletAuthController extends Controller
{
    public function otpOptions(Request $request, ConsumerWalletOtpService $otp, RegionCapabilitiesService $regions): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $otp->otpOptions(
            (string) $request->input('phone'),
            $request->input('country') ? (string) $request->input('country') : null,
        );
        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $region = $regions->forPhone((string) $request->input('phone'));

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'OK',
            'data' => array_filter([
                'whatsapp' => (bool) ($result['whatsapp'] ?? true),
                'email' => (bool) ($result['email'] ?? false),
                'email_masked' => $result['email_masked'] ?? null,
                'otp_blocked' => (bool) ($result['otp_blocked'] ?? false),
                'has_pin' => (bool) ($result['has_pin'] ?? false),
                'wallet_exists' => (bool) ($result['wallet_exists'] ?? false),
                'needs_registration' => (bool) ($result['needs_registration'] ?? false),
                'region' => $region,
                'retry_after_seconds' => $result['retry_after_seconds'] ?? null,
                'lockout_minutes' => $result['lockout_minutes'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
    }

    public function requestOtp(Request $request, ConsumerWalletOtpService $otp): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'channel' => 'nullable|string|in:whatsapp,email',
            'email' => 'nullable|email|max:255',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $otp->requestOtp(
            (string) $request->input('phone'),
            (string) $request->input('channel', 'whatsapp'),
            $request->input('email') ? (string) $request->input('email') : null,
            $request->input('country') ? (string) $request->input('country') : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => array_filter([
                'channel' => $result['channel'] ?? null,
                'otp_blocked' => ($result['otp_blocked'] ?? false) ? true : null,
                'email_masked' => $result['email_masked'] ?? null,
                'fallback_from_whatsapp' => ($result['fallback_from_whatsapp'] ?? false) ? true : null,
                'retry_after_seconds' => $result['retry_after_seconds'] ?? null,
                'lockout_minutes' => $result['lockout_minutes'] ?? null,
            ], fn ($v) => $v !== null),
        ], $result['ok'] ? 200 : ($result['otp_blocked'] ?? false ? 429 : 422));
    }

    public function verifyOtp(Request $request, ConsumerWalletOtpService $otp, ConsumerDeviceTrustService $trust, ConsumerDeviceStepupService $stepup, ConsumerAppSessionService $sessions, RegionCapabilitiesService $regions): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'code' => 'required|string|max:12',
            'country' => 'nullable|string|size:2',
        ]);

        $country = $request->input('country') ? (string) $request->input('country') : null;
        $checked = $otp->checkOtp((string) $request->input('phone'), (string) $request->input('code'), $country);
        if (! $checked['ok']) {
            return response()->json([
                'success' => false,
                'message' => $checked['message'],
            ], 422);
        }

        $e164 = (string) $checked['phone_e164'];
        $region = $regions->forPhone($e164);

        $wallet = Wallet::findByPhoneE164($e164);
        if (! $wallet || $wallet->needsRegistrationProfile()) {
            return response()->json([
                'success' => false,
                'message' => 'Complete registration to create your wallet.',
                'data' => [
                    'needs_registration' => true,
                    'phone_e164' => $e164,
                    'region' => $region,
                ],
            ], 422);
        }

        if ($wallet->isLockedDown()) {
            return response()->json([
                'success' => false,
                'message' => Wallet::lockdownMessage(),
                'data' => ['locked_down' => true],
            ], 423);
        }

        $verified = $otp->verifyOtp((string) $request->input('phone'), (string) $request->input('code'), $country);
        if (! $verified['ok']) {
            return response()->json([
                'success' => false,
                'message' => $verified['message'],
            ], 422);
        }

        $account = ConsumerWalletApiAccount::query()->firstOrNew(['phone_e164' => $e164]);
        $account->wallet_id = $wallet->id;
        $account->phone_e164 = $e164;
        $account->save();

        $deviceId = $sessions->deviceIdFromRequest($request);
        $ctx = $sessions->clientContextFromRequest($request);

        if ($trust->requiresStepUp($account, $deviceId)) {
            $session = $stepup->createSession(
                $account,
                $wallet,
                $deviceId,
                $ctx['platform'],
                $ctx['device_label'],
            );
            $payload = $trust->stepUpPayload($session, $wallet);
            $message = ($payload['stepup_mode'] ?? '') === 'first_device_email'
                ? 'Enter the email code to trust this device'
                : 'Verify this device to continue';

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => array_merge($payload, [
                    'region' => $region,
                ]),
            ], 403);
        }

        $trust->bootstrapTrustedDeviceIfEligible(
            $account,
            $wallet,
            $deviceId,
            $ctx['platform'],
            $ctx['device_label'],
        );

        $account->tokens()->delete();
        $accessToken = $sessions->createAccessToken($account);
        $appSessionId = $sessions->afterTokenIssued($account, ConsumerAppSession::LOGIN_OTP, $request, $accessToken);

        return response()->json([
            'success' => true,
            'message' => 'Signed in.',
            'data' => [
                'token' => $accessToken->plainTextToken,
                'token_type' => 'Bearer',
                'phone_e164' => $e164,
                'wallet_id' => $wallet->id,
                'app_session_id' => $appSessionId,
                'region' => $region,
                'transfer_lock_until' => $account->fresh()->transfer_lock_until?->toIso8601String(),
                'high_value_single_transfer_cap' => $trust->highValueCap(),
            ],
        ]);
    }

    public function register(Request $request, ConsumerWalletRegistrationService $registration, RegionCapabilitiesService $regions): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'code' => 'required|string|max:12',
            'country' => 'nullable|string|size:2',
            'fname' => 'required|string|min:2|max:128',
            'lname' => 'required|string|min:2|max:128',
            'email' => 'required|email|max:255',
            'bvn' => 'nullable|string|regex:/^(\d{11})?$/',
            'nin' => 'nullable|string|regex:/^(\d{11})?$/',
            'dob' => 'nullable|date_format:Y-m-d',
            'gender' => 'nullable|string|in:male,female,M,F,m,f',
            'referral_code' => 'nullable|string|max:64',
        ]);

        $result = $registration->register(
            (string) $request->input('phone'),
            (string) $request->input('code'),
            [
                'fname' => (string) $request->input('fname'),
                'lname' => (string) $request->input('lname'),
                'email' => (string) $request->input('email'),
                'bvn' => $request->input('bvn'),
                'nin' => $request->input('nin'),
                'dob' => $request->input('dob'),
                'gender' => $request->input('gender'),
                'referral_code' => $request->input('referral_code'),
                'country' => $request->input('country') ? (string) $request->input('country') : null,
            ],
        );

        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $account = ConsumerWalletApiAccount::query()->where('phone_e164', $result['phone_e164'])->first();
        $appSessionId = null;
        if ($account instanceof ConsumerWalletApiAccount) {
            $sessions = app(ConsumerAppSessionService::class);
            $ctx = $sessions->clientContextFromRequest($request);
            $wallet = Wallet::query()->find($result['wallet_id']);
            if ($wallet instanceof Wallet) {
                app(\App\Services\Consumer\ConsumerDeviceTrustService::class)->bootstrapTrustedDeviceIfEligible(
                    $account,
                    $wallet,
                    $sessions->deviceIdFromRequest($request),
                    $ctx['platform'],
                    $ctx['device_label'],
                );
            }
            $appSessionId = $sessions->afterPlainTokenIssued(
                $account,
                ConsumerAppSession::LOGIN_REGISTER,
                $request,
            );
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => array_filter([
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'phone_e164' => $result['phone_e164'],
                'wallet_id' => $result['wallet_id'],
                'app_session_id' => $appSessionId,
                'region' => $regions->forPhone((string) ($result['phone_e164'] ?? $request->input('phone'))),
            ], fn ($v) => $v !== null),
        ]);
    }

    public function verifyPin(Request $request, ConsumerWalletPinVerifier $pinVerifier, ConsumerDeviceTrustService $trust, ConsumerDeviceStepupService $stepup, ConsumerAppSessionService $sessions, RegionCapabilitiesService $regions): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'pin' => ['required', 'regex:/^\d{4}$/'],
            'country' => 'nullable|string|size:2',
        ]);

        $e164 = Wallet::resolveAuthE164(
            (string) $request->input('phone'),
            $request->input('country') ? (string) $request->input('country') : null,
        );
        if ($e164 === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid mobile number for a supported country.',
            ], 422);
        }

        $wallet = Wallet::findByPhoneE164($e164);
        if (! $wallet) {
            return response()->json([
                'success' => false,
                'message' => 'No wallet for this number. Sign in with WhatsApp OTP first.',
            ], 422);
        }

        if ($wallet->isLockedDown()) {
            return response()->json([
                'success' => false,
                'message' => Wallet::lockdownMessage(),
                'data' => ['locked_down' => true],
            ], 423);
        }

        if ($wallet->isPinLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet PIN is locked. Try again later or use WhatsApp OTP.',
            ], 423);
        }

        if (! $wallet->hasPin()) {
            return response()->json([
                'success' => false,
                'message' => 'PIN is not set yet. Sign in with OTP, then set your wallet PIN in the app.',
            ], 422);
        }

        if (! $pinVerifier->verify($wallet, (string) $request->input('pin'))) {
            $wallet->increment('pin_failed_attempts');
            $wallet->refresh();
            if ((int) $wallet->pin_failed_attempts >= 5) {
                $wallet->pin_locked_until = now()->addMinutes(15);
                $wallet->save();

                return response()->json([
                    'success' => false,
                    'message' => 'Too many wrong PIN attempts. Wallet PIN locked for 15 minutes.',
                ], 423);
            }

            return response()->json([
                'success' => false,
                'message' => 'Incorrect wallet PIN.',
            ], 422);
        }

        $wallet->pin_failed_attempts = 0;
        $wallet->pin_locked_until = null;
        $wallet->save();

        $account = ConsumerWalletApiAccount::query()->firstOrNew(['phone_e164' => $e164]);
        $account->wallet_id = $wallet->id;
        $account->phone_e164 = $e164;
        $account->save();

        $deviceId = $sessions->deviceIdFromRequest($request);
        $ctx = $sessions->clientContextFromRequest($request);

        if ($trust->requiresStepUp($account, $deviceId)) {
            $session = $stepup->createSession(
                $account,
                $wallet,
                $deviceId,
                $ctx['platform'],
                $ctx['device_label'],
            );
            $payload = $trust->stepUpPayload($session, $wallet);
            $message = ($payload['stepup_mode'] ?? '') === 'first_device_email'
                ? 'Enter the email code to trust this device'
                : 'Verify this device to continue';

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => $payload,
            ], 403);
        }

        $trust->bootstrapTrustedDeviceIfEligible(
            $account,
            $wallet,
            $deviceId,
            $ctx['platform'],
            $ctx['device_label'],
        );

        $account->tokens()->delete();
        $accessToken = $sessions->createAccessToken($account);
        $appSessionId = $sessions->afterTokenIssued($account, ConsumerAppSession::LOGIN_PIN, $request, $accessToken);

        return response()->json([
            'success' => true,
            'message' => 'Signed in.',
            'data' => [
                'token' => $accessToken->plainTextToken,
                'token_type' => 'Bearer',
                'phone_e164' => $e164,
                'wallet_id' => $wallet->id,
                'app_session_id' => $appSessionId,
                'region' => $regions->forPhone($e164),
                'transfer_lock_until' => $account->fresh()->transfer_lock_until?->toIso8601String(),
                'high_value_single_transfer_cap' => $trust->highValueCap(),
            ],
        ]);
    }

    public function recoveryOptions(Request $request, ConsumerWalletPinRecoveryService $recovery): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $recovery->options(
            (string) $request->input('phone'),
            $request->input('country') ? (string) $request->input('country') : null,
        );
        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'] ?? [],
        ]);
    }

    public function recoveryVerifyP2p(Request $request, ConsumerWalletPinRecoveryService $recovery): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'sender_hint' => 'required|string|min:2|max:120',
            'amount' => 'nullable|string|max:24',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $recovery->verifyP2pQuiz(
            (string) $request->input('phone'),
            (string) $request->input('sender_hint'),
            $request->input('amount') !== null ? (string) $request->input('amount') : null,
            $request->input('country') ? (string) $request->input('country') : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => isset($result['recovery_token']) ? ['recovery_token' => $result['recovery_token']] : null,
        ], $result['ok'] ? 200 : 422);
    }

    public function recoveryVerifyBvn(Request $request, ConsumerWalletPinRecoveryService $recovery): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'bvn' => 'required|string|min:11|max:11',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $recovery->verifyBvn(
            (string) $request->input('phone'),
            (string) $request->input('bvn'),
            $request->input('country') ? (string) $request->input('country') : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => isset($result['recovery_token']) ? ['recovery_token' => $result['recovery_token']] : null,
        ], $result['ok'] ? 200 : 422);
    }

    public function recoveryVerifyName(Request $request, ConsumerWalletPinRecoveryService $recovery): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'name' => 'required|string|min:2|max:120',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $recovery->verifyBankName(
            (string) $request->input('phone'),
            (string) $request->input('name'),
            $request->input('country') ? (string) $request->input('country') : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => isset($result['recovery_token']) ? ['recovery_token' => $result['recovery_token']] : null,
        ], $result['ok'] ? 200 : 422);
    }

    public function recoveryResetPin(Request $request, ConsumerWalletPinRecoveryService $recovery): JsonResponse
    {
        $request->validate([
            'recovery_token' => 'required|string|min:32|max:128',
            'pin' => ['required', 'regex:/^\d{4}$/'],
            'pin_confirmation' => ['required', 'regex:/^\d{4}$/'],
        ]);

        $result = $recovery->completeReset(
            (string) $request->input('recovery_token'),
            (string) $request->input('pin'),
            (string) $request->input('pin_confirmation'),
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    public function lockdown(Request $request, ConsumerWalletLockdownService $lockdown): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'pin' => ['required', 'regex:/^\d{4}$/'],
            'dob' => 'required|date_format:Y-m-d',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $lockdown->lockDown(
            (string) $request->input('phone'),
            (string) $request->input('pin'),
            (string) $request->input('dob'),
            $request->input('country') ? (string) $request->input('country') : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    public function lockdownUnlockStart(Request $request, ConsumerWalletLockdownService $lockdown): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'country' => 'nullable|string|size:2',
        ]);

        $result = $lockdown->unlockStart(
            (string) $request->input('phone'),
            $request->input('country') ? (string) $request->input('country') : null,
        );
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['data'] ?? [],
        ]);
    }

    public function lockdownUnlockEmailRequest(Request $request, ConsumerWalletLockdownService $lockdown): JsonResponse
    {
        $request->validate([
            'unlock_token' => 'required|string|min:32|max:128',
            'email' => 'required|email|max:255',
        ]);

        $result = $lockdown->requestUnlockEmail(
            (string) $request->input('unlock_token'),
            (string) $request->input('email'),
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['ok'] ? 200 : 422);
    }

    public function lockdownUnlockEmailVerify(Request $request, ConsumerWalletLockdownService $lockdown): JsonResponse
    {
        $request->validate([
            'unlock_token' => 'required|string|min:32|max:128',
            'code' => 'required|string|max:12',
        ]);

        $result = $lockdown->verifyUnlockEmail(
            (string) $request->input('unlock_token'),
            (string) $request->input('code'),
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    public function lockdownUnlock(Request $request, ConsumerWalletLockdownService $lockdown): JsonResponse
    {
        $request->validate([
            'unlock_token' => 'required|string|min:32|max:128',
            'pin' => ['required', 'regex:/^\d{4}$/'],
            'people' => 'nullable|array|max:6',
            'people.*' => 'string|max:120',
            'bvn' => 'nullable|string|size:11',
            'nin' => 'nullable|string|size:11',
        ]);

        $identity = $request->input('bvn') ?: $request->input('nin');
        $result = $lockdown->unlock(
            (string) $request->input('unlock_token'),
            (string) $request->input('pin'),
            array_values(array_filter((array) $request->input('people', []), 'is_string')),
            $identity !== null ? (string) $identity : null,
        );

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    public function logout(Request $request, ConsumerAppSessionService $sessions): JsonResponse
    {
        return $this->endAppSession($request, $sessions);
    }

    /** Call when the app goes to background so the user must sign in again on return. */
    public function endAppSession(Request $request, ConsumerAppSessionService $sessions): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof ConsumerWalletApiAccount) {
            $sessions->endSession($request, $user);
        }

        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }
}
