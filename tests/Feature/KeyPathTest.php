<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Exceptions\MissingConfigurationException;
use Aliziodev\LaravelMidtrans\Support\KeyResolver;
use Aliziodev\MidtransPhp\Config\MidtransConfig;

/**
 * A PEM is around 1700 characters. Inline in .env it is unreadable and
 * undiffable, so the package reads it from a file instead.
 */
function pemFixture(string $name): string
{
    return __DIR__.'/../Fixtures/'.$name;
}

function keys(): KeyResolver
{
    app()->forgetInstance(MidtransConfig::class);
    app()->forgetInstance(KeyResolver::class);

    return app(KeyResolver::class);
}

it('reads the private key from an absolute path', function () {
    config()->set('midtrans.snap_bi.private_key_path', pemFixture('snapbi_test_private.pem'));

    $key = keys()->privateKey();

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

        expect(keys()->privateKey())->toContain('BEGIN PRIVATE KEY');
    } finally {
        @unlink($absolute);
        @rmdir(dirname($absolute));
    }
});

it('reads the public key the same way', function () {
    config()->set('midtrans.snap_bi.public_key_path', pemFixture('snapbi_test_public.pem'));

    expect(keys()->publicKey())->toContain('BEGIN PUBLIC KEY');
});

it('still accepts an inline PEM for platforms with no writable disk', function () {
    $pem = trim((string) file_get_contents(pemFixture('snapbi_test_private.pem')));

    config()->set('midtrans.snap_bi.private_key_path', null);
    config()->set('midtrans.snap_bi.private_key', $pem);

    expect(keys()->privateKey())->toBe($pem);
});

it('prefers the path when both are set', function () {
    config()->set('midtrans.snap_bi.private_key_path', pemFixture('snapbi_test_private.pem'));
    config()->set('midtrans.snap_bi.private_key', 'inline-nonsense');

    expect(keys()->privateKey())->toContain('BEGIN PRIVATE KEY');
});

it('feeds the resolved key into the SDK config', function () {
    config()->set('midtrans.snap_bi.private_key_path', pemFixture('snapbi_test_private.pem'));

    app()->forgetInstance(MidtransConfig::class);
    app()->forgetInstance(KeyResolver::class);

    expect(app(MidtransConfig::class)->snapBiPrivateKey)->toContain('BEGIN PRIVATE KEY');
});

it('names the file it could not read instead of failing later at the API', function () {
    config()->set('midtrans.snap_bi.private_key_path', 'secrets/does-not-exist.pem');

    expect(fn () => keys()->privateKey())
        ->toThrow(MissingConfigurationException::class, 'does-not-exist.pem');
});

/**
 * Reading is lazy on purpose: registering a service provider should not touch
 * the disk, and an application that never uses Snap-BI should never pay for it.
 */
it('does not touch the disk while the provider registers', function () {
    config()->set('midtrans.snap_bi.private_key_path', 'secrets/does-not-exist.pem');

    // Booting is fine; only asking for the key fails.
    expect(app(KeyResolver::class))->toBeInstanceOf(KeyResolver::class);
});

it('leaves everything null when Snap-BI is not configured at all', function () {
    // The common case: an application using only Core API and Snap. None of the
    // Snap-BI values exist in the dashboard before credential exchange, so this
    // has to work without them.
    config()->set('midtrans.snap_bi', []);

    app()->forgetInstance(MidtransConfig::class);
    app()->forgetInstance(KeyResolver::class);

    $config = app(MidtransConfig::class);

    expect($config->snapBiPrivateKey)->toBeNull()
        ->and($config->snapBiClientId)->toBeNull()
        ->and($config->snapBiPartnerId)->toBeNull()
        ->and($config->coreBaseUrl())->toBe('https://api.sandbox.midtrans.com');
});
