<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Tests;

use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Aliziodev\LaravelMidtrans\Facades\SnapBi;
use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Sandbox-shaped but fake. Real credentials belong in the integration suite,
     * which is opt-in.
     */
    public const SERVER_KEY = 'SB-Mid-server-testing-key';

    protected function getPackageProviders($app): array
    {
        return [MidtransServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Midtrans' => Midtrans::class,
            'SnapBi' => SnapBi::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('midtrans.server_key', self::SERVER_KEY);
        $app['config']->set('midtrans.client_key', 'SB-Mid-client-testing-key');
        $app['config']->set('midtrans.is_production', false);

        /*
        | Unit tests must not see whatever the developer has in .env. Without
        | this, a real MIDTRANS_SNAP_BI_PRIVATE_KEY_PATH reaches the provider and
        | resolves against Testbench's skeleton rather than the package root, so
        | every test fails on a file that was never meant to be involved.
        */
        $app['config']->set('midtrans.snap_bi', []);

        // Deterministic tests: no waiting, and no cross-test cache bleed.
        $app['config']->set('midtrans.max_retries', 0);
        $app['config']->set('midtrans.webhook.deduplicate.ttl', 0);
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Builds a notification body signed the way Midtrans signs it:
     * sha512(order_id + status_code + gross_amount + server_key).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function signedPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'transaction_time' => '2026-08-30 10:00:00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-0001',
            'status_message' => 'midtrans payment notification',
            'status_code' => '200',
            'signature_key' => '',
            'payment_type' => 'gopay',
            'order_id' => 'ORDER-1001',
            'merchant_id' => 'M-0001',
            'gross_amount' => '10000.00',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ], $overrides);

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        return $payload;
    }
}
