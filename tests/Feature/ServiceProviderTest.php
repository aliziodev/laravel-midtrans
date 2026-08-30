<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Exceptions\MissingConfigurationException;
use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Aliziodev\LaravelMidtrans\Facades\SnapBi;
use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;

it('binds both clients as singletons', function () {
    expect(app(MidtransClient::class))->toBeInstanceOf(MidtransClient::class)
        ->and(app(MidtransClient::class))->toBe(app(MidtransClient::class))
        ->and(app(SnapBiClient::class))->toBeInstanceOf(SnapBiClient::class)
        ->and(app(MidtransServiceProvider::CLIENT))->toBe(app(MidtransClient::class))
        ->and(app(MidtransServiceProvider::SNAP_BI_CLIENT))->toBe(app(SnapBiClient::class));
});

it('gives both clients the same transport, so one fake covers both', function () {
    expect(app(Transport::class))->toBe(app(Transport::class));
});

it('maps the Laravel config onto the SDK config', function () {
    config()->set([
        'midtrans.is_production' => true,
        'midtrans.timeout' => 45,
        'midtrans.max_retries' => 5,
        'midtrans.retry_delay_ms' => 750,
        'midtrans.idempotency_key_prefix' => 'shop',
        'midtrans.payment_locale' => 'en-EN',
        'midtrans.snap_bi.partner_id' => 'BMRI',
        'midtrans.snap_bi.channel_id' => '12345',
    ]);
    app()->forgetInstance(MidtransConfig::class);

    $config = app(MidtransConfig::class);

    expect($config->isProduction)->toBeTrue()
        ->and($config->timeoutSeconds)->toBe(45)
        ->and($config->maxRetries)->toBe(5)
        ->and($config->retryDelayMs)->toBe(750)
        ->and($config->idempotencyKeyPrefix)->toBe('shop')
        ->and($config->paymentLocale)->toBe('en-EN')
        ->and($config->snapBiPartnerId)->toBe('BMRI')
        ->and($config->snapBiChannelId)->toBe('12345')
        ->and($config->coreBaseUrl())->toBe('https://api.midtrans.com');
});

it('turns blank env values into null rather than empty strings', function () {
    // An unset env var arrives as '', which would otherwise be sent as a header
    // with an empty value.
    config()->set(['midtrans.payment_locale' => '', 'midtrans.snap_bi.device_id' => '']);
    app()->forgetInstance(MidtransConfig::class);

    expect(app(MidtransConfig::class)->paymentLocale)->toBeNull()
        ->and(app(MidtransConfig::class)->snapBiDeviceId)->toBeNull();
});

it('defaults to sandbox', function () {
    expect(app(MidtransConfig::class)->coreBaseUrl())->toBe('https://api.sandbox.midtrans.com');
});

it('names the missing server key instead of failing at the API', function () {
    config()->set('midtrans.server_key', '');
    app()->forgetInstance(MidtransConfig::class);

    expect(fn () => app(MidtransConfig::class))
        ->toThrow(MissingConfigurationException::class, 'MIDTRANS_SERVER_KEY');
});

it('resolves both facades', function () {
    expect(Midtrans::getFacadeRoot())->toBeInstanceOf(MidtransClient::class)
        ->and(SnapBi::getFacadeRoot())->toBeInstanceOf(SnapBiClient::class);
});

it('offers the config for publishing', function () {
    expect(MidtransServiceProvider::pathsToPublish(MidtransServiceProvider::class, 'midtrans-config'))
        ->not->toBeEmpty();
});

it('keeps the credentials out of a dumped config object', function () {
    $dumped = var_export(app(MidtransConfig::class)->__debugInfo(), true);

    expect($dumped)->not->toContain('SB-Mid-server-testing-key')
        ->and($dumped)->toContain('[redacted]');
});
