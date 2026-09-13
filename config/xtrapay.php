<?php

return [
    'demo_otp' => env('XTRAPAY_DEMO_OTP', '123456'),
    'demo_pin' => env('XTRAPAY_DEMO_PIN', '1234'),
    'frontend_url' => env('XTRAPAY_FRONTEND_URL', 'http://127.0.0.1:5173'),
    /** When false, POST /transfers refuses with a clear error (no silent mock settlement). */
    'live_transfers' => filter_var(env('XTRAPAY_LIVE_TRANSFERS', false), FILTER_VALIDATE_BOOL),
    /** Max NGN per live transfer while testing (0 = no extra cap). */
    'live_transfer_max' => (float) env('XTRAPAY_LIVE_TRANSFER_MAX', 100),
];
