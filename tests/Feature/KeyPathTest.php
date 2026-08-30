<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Exceptions\MissingConfigurationException;
use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Aliziodev\MidtransPhp\Config\MidtransConfig;

/**
 * A PEM is around 1700 characters. Inline in .env it is unreadable and
 * undiffable, so the package reads it from a file instead.
 */
function pemFixture(string $name): string
{
    return __DIR__.'/../Fixtures/'.$name;
}

function reboot(): void
{
    app()->forgetInstance(MidtransConfig::class);
    (new MidtransServiceProvider(app()))->register();
}

it('reads the private key from an absolute path', function () {
    config()->set('midtrans.snap_bi.private_key_path', pemFixture('snapbi_test_private.pem'));
    config()->set('midtrans.snap_bi.private_key', null);

    reboot();

    $key = app(MidtransConfig::class)->snapBiPrivateKey;

    expect($key)->toContain('BEGIN PRIVATE KEY')
        ->and(openssl_pkey_get_private((string) $key))->not->toBeFalse();
});

it('resolves a relative path against the application root', function () {
    // What a real application writes: secrets/snapbi-private.pem, relative to
    // the app root rather than to wherever the process was started.
    $relative = 'secrets/snapbi-private.pem';
    $absolute = base_path($relative);

    @mkdir(dirname($absolute), 0o755, true);
    copy(pemFixture('snapbi_test_private.pem'), $absolute);

    try {
        config()->set('midtrans.snap_bi.private_key_path', $relative);
        config()->set('midtrans.snap_bi.private_key', null);

        reboot();

        expect(app(MidtransConfig::class)->snapBiPrivateKey)->toContain('BEGIN PRIVATE KEY');
    } finally {
        @unlink($absolute);
        @rmdir(dirname($absolute));
    }
});

it('resolves the public key into config, so the middleware needs no path awareness', function () {
    config()->set('midtrans.snap_bi.public_key_path', pemFixture('snapbi_test_public.pem'));
    config()->set('midtrans.snap_bi.public_key', null);

    reboot();

    expect(config('midtrans.snap_bi.public_key'))->toContain('BEGIN PUBLIC KEY');
});

it('still accepts an inline PEM for platforms with no writable disk', function () {
    $pem = (string) file_get_contents(pemFixture('snapbi_test_private.pem'));

    config()->set('midtrans.snap_bi.private_key_path', null);
    config()->set('midtrans.snap_bi.private_key', $pem);

    reboot();

    expect(app(MidtransConfig::class)->snapBiPrivateKey)->toBe($pem);
});

it('prefers the path when both are set', function () {
    config()->set('midtrans.snap_bi.private_key_path', pemFixture('snapbi_test_private.pem'));
    config()->set('midtrans.snap_bi.private_key', 'inline-nonsense');

    reboot();

    expect(app(MidtransConfig::class)->snapBiPrivateKey)->toContain('BEGIN PRIVATE KEY');
});

it('names the file it could not read instead of failing later at the API', function () {
    config()->set('midtrans.snap_bi.private_key_path', 'secrets/does-not-exist.pem');

    expect(fn () => reboot())
        ->toThrow(MissingConfigurationException::class, 'does-not-exist.pem');
});

it('leaves everything null when Snap-BI is not configured at all', function () {
    // The common case: an application using only Core API and Snap. None of the
    // Snap-BI values exist in the dashboard before credential exchange, so this
    // has to work without them.
    config()->set('midtrans.snap_bi', []);

    reboot();

    $config = app(MidtransConfig::class);

    expect($config->snapBiPrivateKey)->toBeNull()
        ->and($config->snapBiClientId)->toBeNull()
        ->and($config->snapBiPartnerId)->toBeNull()
        ->and($config->coreBaseUrl())->toBe('https://api.sandbox.midtrans.com');
});
