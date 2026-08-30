<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\MidtransClient;

/**
 * Runs against the real Midtrans sandbox.
 *
 * Everything else in this suite is faked, which proves the package builds the
 * right request but not that Midtrans agrees the endpoint exists. These do.
 *
 * Enable with:
 *   MIDTRANS_SANDBOX_SERVER_KEY=SB-Mid-server-xxx vendor/bin/pest --group=sandbox
 */
beforeEach(function () {
    $serverKey = trim(getenv('MIDTRANS_SANDBOX_SERVER_KEY') ?: '');

    if ($serverKey === '' || str_ends_with($serverKey, '-server-')) {
        $this->markTestSkipped('Set MIDTRANS_SANDBOX_SERVER_KEY in .env to run the sandbox suite.');
    }

    config()->set('midtrans.server_key', $serverKey);
    config()->set('midtrans.client_key', getenv('MIDTRANS_SANDBOX_CLIENT_KEY') ?: null);
    config()->set('midtrans.is_production', false);
    config()->set('midtrans.max_retries', 1);

    app()->forgetInstance(MidtransConfig::class);
    app()->forgetInstance(MidtransClient::class);
    app()->forgetInstance(MidtransServiceProvider::CLIENT);

    /*
    | Asserted from the resolved host, not the key's prefix. Sandbox keys used
    | to start with SB-Mid-server- and newer ones start with Mid-server-, which
    | is what production keys have always looked like. The host is what decides
    | whether these tests can move real money.
    */
    expect(app(MidtransConfig::class)->coreBaseUrl())->toContain('sandbox');
});

function sandboxOrderId(string $prefix = 'PKG'): string
{
    return $prefix.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
}

it('creates a Snap transaction and gets a token back', function () {
    $orderId = sandboxOrderId('SNAP');

    $result = Midtrans::createSnapTransaction([
        'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 10000],
    ]);

    expect($result)->toHaveKeys(['token', 'redirect_url'])
        ->and($result['token'])->not->toBeEmpty();
})->group('sandbox');

it('reads the status of a transaction it just created', function () {
    $orderId = sandboxOrderId('STATUS');

    Midtrans::createSnapTransaction([
        'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 15000],
    ]);

    // A Snap token alone has no transaction yet, so Midtrans answers 404 until
    // the customer picks a payment method. Both outcomes prove the endpoint and
    // the auth header are right.
    try {
        $status = Midtrans::getTransactionStatus($orderId);
        expect($status['order_id'])->toBe($orderId);
    } catch (MidtransApiException $exception) {
        expect($exception->statusCode)->toBe(404)
            ->and($exception->getMessage())->toContain("doesn't exist");
    }
})->group('sandbox');

it('charges a bank transfer and returns virtual account numbers', function () {
    $orderId = sandboxOrderId('VA');

    $result = Midtrans::chargeTransaction([
        'payment_type' => 'bank_transfer',
        'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 20000],
        'bank_transfer' => ['bank' => 'bca'],
    ]);

    expect($result['transaction_status'])->toBe('pending')
        ->and($result['va_numbers'][0]['bank'])->toBe('bca')
        ->and($result['va_numbers'][0]['va_number'])->not->toBeEmpty()
        // The amount comes back as an exact string, which is what the webhook
        // signature is computed over.
        ->and($result['gross_amount'])->toBe('20000.00');
})->group('sandbox');

it('cancels a pending transaction', function () {
    $orderId = sandboxOrderId('CANCEL');

    Midtrans::chargeTransaction([
        'payment_type' => 'bank_transfer',
        'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 25000],
        'bank_transfer' => ['bank' => 'bni'],
    ]);

    expect(Midtrans::cancelTransaction($orderId)['transaction_status'])->toBe('cancel');
})->group('sandbox');

it('reaches the BIN API endpoint added in SDK 2.0', function () {
    // Path was taken from the documentation and had never been called. This is
    // the check that it is real.
    $result = Midtrans::getBin('48111111');

    expect($result)->toBeArray()->toHaveKey('data');
})->group('sandbox');

it('reaches the Snap preference endpoint on the v3 host', function () {
    $result = Midtrans::getSnapPreferences();

    expect($result)->toBeArray()->not->toBeEmpty();
})->group('sandbox');

it('rejects a refund without a refund_key while retries are on', function () {
    // The guard is local, so this never leaves the process — but it is the rule
    // that stops a retried refund paying out twice.
    expect(fn () => Midtrans::refundTransaction('ANY-ORDER', ['amount' => 1000]))
        ->toThrow(MidtransException::class, 'refund_key is required');
})->group('sandbox');

it('signs a webhook the same way Midtrans does', function () {
    $orderId = sandboxOrderId('SIGN');

    $charge = Midtrans::chargeTransaction([
        'payment_type' => 'bank_transfer',
        'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 30000],
        'bank_transfer' => ['bank' => 'bri'],
    ]);

    // Rebuild the signature from a real API response and post it at our own
    // webhook: if the two agree, real notifications will verify.
    $payload = [
        'order_id' => $charge['order_id'],
        'status_code' => $charge['status_code'],
        'gross_amount' => $charge['gross_amount'],
        'transaction_id' => $charge['transaction_id'],
        'transaction_status' => $charge['transaction_status'],
        'payment_type' => $charge['payment_type'],
    ];
    $payload['signature_key'] = hash(
        'sha512',
        $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('midtrans.server_key'),
    );

    $this->postJson('/midtrans/webhook', $payload)->assertOk();
})->group('sandbox');
