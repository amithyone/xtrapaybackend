<?php

namespace Tests\Unit\Xtrapay;

use App\Services\Xtrapay\XtrapaySignupAttemptAdminService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class XtrapaySignupAttemptAdminServiceTest extends TestCase
{
    public function test_clear_email_hold_removes_dedupe_keys(): void
    {
        config(['cache.default' => 'array']);

        $email = 'stuck.user@example.com';
        $service = app(XtrapaySignupAttemptAdminService::class);

        Cache::put('xtrapay:otp-email-sent:'.sha1($email.'|login'), '123456', now()->addMinutes(5));
        Cache::put('xtrapay:otp-email-sent:'.sha1($email.'|register'), '123456', now()->addMinutes(5));

        $before = $service->emailHoldStatus($email);
        $this->assertTrue($before['held']);

        $result = $service->clearEmailHold($email);
        $this->assertGreaterThanOrEqual(2, $result['cleared_count']);
        $this->assertFalse($service->emailHoldStatus($email)['held']);
    }

    public function test_email_hold_status_empty_for_unknown_email(): void
    {
        config(['cache.default' => 'array']);

        $status = app(XtrapaySignupAttemptAdminService::class)->emailHoldStatus('nobody@example.com');

        $this->assertFalse($status['held']);
        $this->assertSame(0, $status['seconds_remaining']);
        $this->assertSame([], $status['holds']);
    }
}
