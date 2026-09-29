<?php

return [
    /** Enable passkey device trust, step-up, and transfer lock enforcement. */
    'device_trust_enabled' => filter_var(env('CONSUMER_DEVICE_TRUST_ENABLED', true), FILTER_VALIDATE_BOOL),

    /**
     * When true, PIN/OTP login returns 403 “Verify this device” if the account already has a
     * KYC-trusted device and the request X-Device-Id does not match.
     */
    'device_stepup_required_on_login' => filter_var(env('CONSUMER_DEVICE_STEPUP_REQUIRED_ON_LOGIN', true), FILTER_VALIDATE_BOOL),

    /**
     * Existing wallets with no trusted device must verify an email OTP once before the
     * current install becomes trusted (devices stay untrusted until then).
     */
    'device_first_trust_email_otp' => filter_var(env('CONSUMER_DEVICE_FIRST_TRUST_EMAIL_OTP', true), FILTER_VALIDATE_BOOL),

    /** WebAuthn relying party ID (must match associated domains / asset links). */
    'webauthn_rp_id' => env('CONSUMER_WEBAUTHN_RP_ID', 'check-outpay.com'),

    /** WebAuthn relying party display name. */
    'webauthn_rp_name' => env('CONSUMER_WEBAUTHN_RP_NAME', 'CheckoutNow'),

    /**
     * Allowed clientDataJSON origins for native passkeys (comma-separated).
     * iOS: https://check-outpay.com
     * Android: android:apk-key-hash:… (or set CONSUMER_WEBAUTHN_ANDROID_APK_KEY_HASHES)
     */
    'webauthn_allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env(
            'CONSUMER_WEBAUTHN_ALLOWED_ORIGINS',
            'https://check-outpay.com'
        ))
    ))),

    /**
     * Comma-separated Base64URL SHA-256 cert hashes for Android passkeys → android:apk-key-hash: origins.
     *
     * @var list<string>
     */
    'webauthn_android_apk_key_hashes' => array_values(array_filter(array_map(
        static fn (string $hash): string => trim($hash),
        explode(',', (string) env('CONSUMER_WEBAUTHN_ANDROID_APK_KEY_HASHES', ''))
    ))),

    /** SHA-256 fingerprints for public/.well-known/assetlinks.json (colon-separated hex). */
    'android_assetlinks_sha256_fingerprints' => array_values(array_filter(array_map(
        static fn (string $fp): string => trim($fp),
        explode(',', (string) env('CONSUMER_ANDROID_ASSETLINKS_SHA256', ''))
    ))),

    /** Max single transfer amount (NGN) while transfer lock is active after new-device KYC. */
    'high_value_single_transfer_cap' => (int) env('CONSUMER_HIGH_VALUE_SINGLE_TRANSFER_CAP', 20000),

    /** Hours to lock high-value transfers after binding a new trusted device. */
    'transfer_lock_hours' => (int) env('CONSUMER_TRANSFER_LOCK_HOURS', 48),

    /**
     * Browser / Expo web wallet: daily outbound spend cap (NGN). Phone app is not capped by this.
     */
    'web_daily_transfer_cap_enabled' => filter_var(env('CONSUMER_WEB_DAILY_TRANSFER_CAP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'web_daily_transfer_cap_ngn' => (float) env('CONSUMER_WEB_DAILY_TRANSFER_CAP_NGN', 10000),

    /** Short-lived passkey payment_token TTL (minutes). */
    'payment_token_ttl_minutes' => (int) env('CONSUMER_PAYMENT_TOKEN_TTL_MINUTES', 5),

    /** Sanctum token name for consumer mobile sessions. */
    'token_name' => env('CONSUMER_WALLET_TOKEN_NAME', 'consumer_mobile'),

    /** Per-wallet API budget for authenticated consumer routes (history/utility paginate in bursts). */
    'rate_limit_per_minute' => (int) env('CONSUMER_WALLET_RATE_LIMIT_PER_MINUTE', 240),

    /** Server cache for merged merchant business activity (Utility full view). */
    'business_activity_cache_ttl_full' => (int) env('CONSUMER_BUSINESS_ACTIVITY_CACHE_TTL_FULL', 1800),

    /** Server cache for business account history (pay-ins + withdrawals). */
    'business_activity_cache_ttl_account' => (int) env('CONSUMER_BUSINESS_ACTIVITY_CACHE_TTL_ACCOUNT', 600),

    /** FCM push approval for new-device step-up (trusted device approves sign-in). */
    'device_stepup_push_enabled' => filter_var(env('CONSUMER_DEVICE_STEPUP_PUSH_ENABLED', true), FILTER_VALIDATE_BOOL),
    'device_stepup_push_ttl_minutes' => (int) env('CONSUMER_DEVICE_STEPUP_PUSH_TTL_MINUTES', 5),
    'device_stepup_push_poll_seconds' => (int) env('CONSUMER_DEVICE_STEPUP_PUSH_POLL_SECONDS', 3),
    'device_stepup_push_title' => env('CONSUMER_DEVICE_STEPUP_PUSH_TITLE', 'New sign-in attempt'),
    'device_stepup_push_channel' => env('CONSUMER_DEVICE_STEPUP_PUSH_CHANNEL', 'wallet_alerts'),

    /**
     * End wallet app session after this many minutes without API activity (forces re-login).
     * While the app keeps calling the API, the session stays alive (sliding idle).
     */
    'app_session_idle_minutes' => max(1, (int) env('CONSUMER_APP_SESSION_IDLE_MINUTES', 60)),

    /** App login / registration OTP. */
    'otp_ttl_seconds' => max(60, (int) env('CONSUMER_OTP_TTL_SECONDS', 600)),
    'otp_length' => max(4, min(8, (int) env('CONSUMER_OTP_LENGTH', 6))),
    'otp_max_attempts' => max(3, (int) env('CONSUMER_OTP_MAX_ATTEMPTS', 5)),
    /** Unused OTP requests (codes sent but never verified) before temporary lockout. */
    'otp_max_unused_sends' => max(2, min(10, (int) env('CONSUMER_OTP_MAX_UNUSED_SENDS', 3))),
    /** How long unused-OTP / wrong-code lockouts last (minutes). */
    'otp_lockout_minutes' => max(1, min(60, (int) env('CONSUMER_OTP_LOCKOUT_MINUTES', 10))),

    /**
     * Hard Sanctum token lifetime (minutes). Extended on every authenticated request.
     * Must be >> idle minutes so active users are not kicked by a fixed clock.
     */
    'token_absolute_lifetime_minutes' => max(60, (int) env('CONSUMER_TOKEN_ABSOLUTE_LIFETIME_MINUTES', 60 * 24 * 30)),

    /**
     * Kenya Tier 2 via Smile ID (National ID). Also overridable by Setting kenya_tier2_enabled.
     * Requires SMILE_ID_PARTNER_ID + SMILE_ID_API_KEY.
     */
    'kenya_tier2_enabled' => filter_var(env('CONSUMER_KENYA_TIER2_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** P2P money requests (ask someone to pay you). */
    'money_request_enabled' => filter_var(env('CONSUMER_MONEY_REQUEST_ENABLED', true), FILTER_VALIDATE_BOOL),
    'money_request_expiry_days' => (int) env('CONSUMER_MONEY_REQUEST_EXPIRY_DAYS', 7),
    'money_request_max_pending_per_pair' => (int) env('CONSUMER_MONEY_REQUEST_MAX_PENDING_PER_PAIR', 3),

    /** Save Together group savings pots. */
    'save_together_enabled' => filter_var(env('CONSUMER_SAVE_TOGETHER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'save_together_min_members' => (int) env('CONSUMER_SAVE_TOGETHER_MIN_MEMBERS', 2),
    'save_together_max_members' => (int) env('CONSUMER_SAVE_TOGETHER_MAX_MEMBERS', 20),
    'save_together_min_target' => (float) env('CONSUMER_SAVE_TOGETHER_MIN_TARGET', 100),
    'save_together_min_contribution' => (float) env('CONSUMER_SAVE_TOGETHER_MIN_CONTRIBUTION', 1),

    /** CAC business name registration + business receive account (CheckoutNow Receive Funds). */
    'business_name_registration' => [
        'enabled' => filter_var(env('CONSUMER_BUSINESS_NAME_REGISTRATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'fee_amount' => (float) env('CONSUMER_BUSINESS_NAME_REGISTRATION_FEE', 15000),
        'fee_currency' => env('CONSUMER_BUSINESS_NAME_REGISTRATION_FEE_CURRENCY', 'NGN'),
        'coming_soon_message' => env(
            'CONSUMER_BUSINESS_NAME_REGISTRATION_COMING_SOON',
            'Business name registration coming soon.'
        ),
        'estimated_completion_hours_min' => (int) env('CONSUMER_BUSINESS_NAME_REGISTRATION_HOURS_MIN', 12),
        'estimated_completion_hours_max' => (int) env('CONSUMER_BUSINESS_NAME_REGISTRATION_HOURS_MAX', 24),
        'requirements' => [
            'Two business name options in order of preference',
            'Owner/director full legal name',
            'Government ID upload — NIN preferred (passport or driver\'s licence also accepted)',
            'Registered business address in Nigeria',
            'Short description of what the business does',
            'Registration fee debited from your wallet balance',
        ],
    ],

    /**
     * Wallet referral programme (admin Setting keys override these defaults).
     * Commercial numbers must never be hardcoded in services — use WalletReferralSettingsService.
     */
    'referral' => [
        'enabled' => filter_var(env('CONSUMER_REFERRAL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'bonus_months' => max(1, (int) env('CONSUMER_REFERRAL_BONUS_MONTHS', 6)),
        'first_deposit_percent' => (float) env('CONSUMER_REFERRAL_FIRST_DEPOSIT_PERCENT', 5),
        'first_deposit_max_ngn' => env('CONSUMER_REFERRAL_FIRST_DEPOSIT_MAX_NGN') !== null
            && env('CONSUMER_REFERRAL_FIRST_DEPOSIT_MAX_NGN') !== ''
            ? (float) env('CONSUMER_REFERRAL_FIRST_DEPOSIT_MAX_NGN')
            : null,
        'first_deposit_min_ngn' => (float) env('CONSUMER_REFERRAL_FIRST_DEPOSIT_MIN_NGN', 0),
        'milestone_every' => max(1, (int) env('CONSUMER_REFERRAL_MILESTONE_EVERY', 100)),
        'milestone_amount_ngn' => (float) env('CONSUMER_REFERRAL_MILESTONE_AMOUNT_NGN', 200),
        'leaderboard_enabled' => filter_var(env('CONSUMER_REFERRAL_LEADERBOARD_ENABLED', true), FILTER_VALIDATE_BOOL),
        'leaderboard_month_pot_ngn' => (float) env('CONSUMER_REFERRAL_LEADERBOARD_POT_NGN', 0),
        'leaderboard_top_n' => max(1, (int) env('CONSUMER_REFERRAL_LEADERBOARD_TOP_N', 10)),
        'leaderboard_split' => env('CONSUMER_REFERRAL_LEADERBOARD_SPLIT', 'equal'),
        'timezone' => env('CONSUMER_REFERRAL_TIMEZONE', 'Africa/Lagos'),
    ],

    /**
     * Daily “complete a transaction” nudges (Laravel schedule 09:00 / 18:00 Africa/Lagos).
     * App push only — never WhatsApp.
     */
    'inactive_reminders_enabled' => filter_var(env('CONSUMER_INACTIVE_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOL),
    'inactive_reminder_min_balance' => (float) env('CONSUMER_INACTIVE_REMINDER_MIN_BALANCE', 1),
    'inactive_reminder_timezone' => env('CONSUMER_INACTIVE_REMINDER_TIMEZONE', 'Africa/Lagos'),
    'inactive_reminder_push_title' => env('CONSUMER_INACTIVE_REMINDER_PUSH_TITLE', 'Hope your day is going well'),
    'inactive_reminder_push_channel' => env('CONSUMER_INACTIVE_REMINDER_PUSH_CHANNEL', 'wallet_alerts'),

    /** CheckoutPay merchant business account onboarding from CheckoutNow app. */
    'business_account_onboarding' => [
        'enabled' => filter_var(env('CONSUMER_BUSINESS_ACCOUNT_ONBOARDING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'fee_amount' => (float) env('CONSUMER_BUSINESS_ACCOUNT_ONBOARDING_FEE', 0),
        'fee_currency' => env('CONSUMER_BUSINESS_ACCOUNT_ONBOARDING_FEE_CURRENCY', 'NGN'),
        'coming_soon_message' => env(
            'CONSUMER_BUSINESS_ACCOUNT_ONBOARDING_COMING_SOON',
            'Business account onboarding coming soon.'
        ),
        'dashboard_login_url' => env('CONSUMER_BUSINESS_ACCOUNT_DASHBOARD_LOGIN_URL', '/dashboard/login'),
        'service_categories' => [
            ['id' => 'payments', 'label' => 'Payments & checkout'],
            ['id' => 'rentals', 'label' => 'Rentals'],
            ['id' => 'memberships', 'label' => 'Memberships'],
            ['id' => 'tickets', 'label' => 'Event tickets'],
            ['id' => 'charity', 'label' => 'Charity & donations'],
            ['id' => 'invoices', 'label' => 'Invoices'],
        ],
    ],

    /** Public base for signed wallet receive QR links (CheckoutNow consumer app). */
    'pay_qr_base_url' => rtrim((string) env('CONSUMER_PAY_QR_BASE_URL', 'https://app.check-outnow.com'), '/'),

    /** Optional HMAC secret for pay QR tokens (defaults to APP_KEY). */
    'pay_qr_secret' => (string) env('CONSUMER_PAY_QR_SECRET', ''),
];
