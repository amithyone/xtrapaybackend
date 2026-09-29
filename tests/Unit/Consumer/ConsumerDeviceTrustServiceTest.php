<?php

namespace Tests\Unit\Consumer;

use App\Models\ConsumerWalletApiAccount;
use App\Services\Consumer\ConsumerDeviceTrustService;
use Tests\TestCase;

class ConsumerDeviceTrustServiceTest extends TestCase
{
    public function test_high_value_transfer_blocked_when_lock_active(): void
    {
        config([
            'consumer_wallet.high_value_single_transfer_cap' => 20000,
        ]);

        $account = new ConsumerWalletApiAccount([
            'transfer_lock_until' => now()->addHour(),
        ]);

        $service = $this->app->make(ConsumerDeviceTrustService::class);

        $this->assertTrue($service->isHighValueTransferBlocked($account, 20001));
        $this->assertFalse($service->isHighValueTransferBlocked($account, 20000));
        $this->assertFalse($service->isHighValueTransferBlocked($account, 5000));
    }

    public function test_transfer_lock_meta_shape(): void
    {
        config([
            'consumer_wallet.high_value_single_transfer_cap' => 20000,
        ]);

        $account = new ConsumerWalletApiAccount([
            'transfer_lock_until' => now()->addHours(6),
            'pin_reset_required' => false,
        ]);

        $service = $this->app->make(ConsumerDeviceTrustService::class);
        $meta = $service->transferLockMeta($account);

        $this->assertSame(20000, $meta['high_value_single_transfer_cap']);
        $this->assertTrue($meta['high_value_transfer_blocked']);
        $this->assertFalse($meta['pin_reset_required']);
        $this->assertNotNull($meta['transfer_lock_until']);
    }

    public function test_requires_step_up_for_existing_user_without_trusted_device(): void
    {
        config([
            'consumer_wallet.device_trust_enabled' => true,
            'consumer_wallet.device_stepup_required_on_login' => true,
            'consumer_wallet.device_first_trust_email_otp' => true,
        ]);

        $service = $this->app->make(ConsumerDeviceTrustService::class);
        $account = new ConsumerWalletApiAccount(['id' => 1]);
        $account->setRelation('trustedDevices', collect());

        $this->assertTrue($service->requiresStepUp($account, 'device-a'));
        $this->assertSame('first_device_email', $service->stepUpMode($account, 'device-a'));
    }

    public function test_first_device_email_stepup_can_be_disabled(): void
    {
        config([
            'consumer_wallet.device_trust_enabled' => true,
            'consumer_wallet.device_stepup_required_on_login' => true,
            'consumer_wallet.device_first_trust_email_otp' => false,
        ]);

        $service = $this->app->make(ConsumerDeviceTrustService::class);
        $account = new ConsumerWalletApiAccount(['id' => 1]);
        $account->setRelation('trustedDevices', collect());

        $this->assertFalse($service->requiresStepUp($account, 'device-a'));
    }

    public function test_device_mismatch_payload_when_trusted_device_exists(): void
    {
        config([
            'consumer_wallet.device_trust_enabled' => true,
            'consumer_wallet.device_stepup_required_on_login' => true,
        ]);

        $service = $this->app->make(ConsumerDeviceTrustService::class);
        $trusted = new \App\Models\ConsumerTrustedDevice([
            'device_id' => 'phone-one',
            'label' => 'Pixel',
            'kyc_confirmed_at' => now(),
        ]);
        $trusted->setRelation('passkey', null);
        $account = new ConsumerWalletApiAccount(['id' => 2]);
        $account->setRelation('trustedDevices', collect([$trusted]));

        $this->assertTrue($service->requiresStepUp($account, 'phone-two'));
        $this->assertFalse($service->requiresStepUp($account, 'phone-one'));
        $response = $service->deviceMismatchJsonResponse($account, 'phone-two');
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('device_mismatch', $response->getData(true)['code']);
    }
}
