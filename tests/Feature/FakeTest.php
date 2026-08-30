<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Aliziodev\LaravelMidtrans\Facades\SnapBi;
use Aliziodev\LaravelMidtrans\Testing\RecordedRequest;
use Aliziodev\LaravelMidtrans\Testing\RecordingTransport;
use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;

it('records a request instead of sending it', function () {
    $transport = Midtrans::fake();

    Midtrans::chargeTransaction([
        'payment_type' => 'gopay',
        'transaction_details' => ['order_id' => 'ORDER-1', 'gross_amount' => 10000],
    ]);

    expect($transport->recorded())->toHaveCount(1);

    Midtrans::assertSent(
        fn (RecordedRequest $request): bool => $request->method === 'POST'
            && $request->urlIs('*/v2/charge')
            && $request->input('transaction_details.order_id') === 'ORDER-1',
    );
});

it('returns the body queued for a matching url', function () {
    Midtrans::fake([
        '*/v2/charge' => ['status_code' => '201', 'order_id' => 'ORDER-1'],
        '*/status' => ['transaction_status' => 'settlement'],
    ]);

    expect(Midtrans::chargeTransaction(['payment_type' => 'gopay'])['status_code'])->toBe('201')
        ->and(Midtrans::getTransactionStatus('ORDER-1')['transaction_status'])->toBe('settlement');
});

it('answers unmatched urls with an empty body rather than reaching the network', function () {
    Midtrans::fake();

    expect(Midtrans::getTransactionStatus('ORDER-UNKNOWN'))->toBe([]);
});

it('plays queued responses in order, which is how a retry is exercised', function () {
    $transport = Midtrans::fake();
    $transport->push(['transaction_status' => 'pending'])
        ->push(['transaction_status' => 'settlement']);

    expect(Midtrans::getTransactionStatus('ORDER-1')['transaction_status'])->toBe('pending')
        ->and(Midtrans::getTransactionStatus('ORDER-1')['transaction_status'])->toBe('settlement');
});

it('fakes the Snap-BI client through the same transport', function () {
    $transport = Midtrans::fake([
        '*/access-token/b2b' => ['accessToken' => 'tok-1', 'expiresIn' => '900'],
        '*/qr-mpm-generate' => ['responseCode' => '2004700', 'qrContent' => '000201...'],
    ]);

    config()->set([
        'midtrans.snap_bi.client_id' => 'client-id',
        'midtrans.snap_bi.private_key' => file_get_contents(__DIR__.'/../Fixtures/snapbi_test_private.pem'),
        'midtrans.snap_bi.client_secret' => 'secret',
        'midtrans.snap_bi.partner_id' => 'partner',
    ]);
    app()->forgetInstance(MidtransConfig::class);
    app()->forgetInstance(SnapBiClient::class);

    $result = SnapBi::createQris(['partnerReferenceNo' => 'REF-1'], 'EXT-1');

    expect($result['qrContent'])->toBe('000201...')
        ->and($transport->recorded())->toHaveCount(2);
});

it('asserts nothing was sent', function () {
    Midtrans::fake();

    Midtrans::assertNothingSent();
});

it('counts the requests it recorded', function () {
    Midtrans::fake();

    Midtrans::getTransactionStatus('A');
    Midtrans::getTransactionStatus('B');

    Midtrans::assertSentCount(2);
    Midtrans::assertNotSent(fn (RecordedRequest $r): bool => $r->urlIs('*/v2/charge'));
});

it('exposes the headers the SDK generated', function () {
    Midtrans::fake();

    Midtrans::chargeTransaction(['payment_type' => 'gopay']);

    Midtrans::assertSent(function (RecordedRequest $request): bool {
        // A charge must carry a generated Idempotency-Key; the SDK builds one
        // per request rather than reusing a configured value.
        return $request->header('Idempotency-Key') !== null
            && str_starts_with((string) $request->header('Authorization'), 'Basic ');
    });
});

it('restores nothing on its own, so each test gets a fresh transport', function () {
    expect(app(Transport::class))->not->toBeInstanceOf(
        RecordingTransport::class,
    );
});
