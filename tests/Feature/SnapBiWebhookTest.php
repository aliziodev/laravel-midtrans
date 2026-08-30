<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Events\SnapBiWebhookReceived;
use Aliziodev\LaravelMidtrans\Http\Controllers\SnapBiWebhookController;
use Aliziodev\LaravelMidtrans\Http\Middleware\VerifySnapBiSignature;
use Aliziodev\LaravelMidtrans\Webhook\SnapBiNotification;
use Illuminate\Support\Facades\Event;

const SNAP_BI_PATH = '/midtrans/snap-bi/webhook';

beforeEach(function () {
    config()->set('midtrans.snap_bi_webhook.enabled', true);
    config()->set('midtrans.snap_bi.public_key', file_get_contents(__DIR__.'/../Fixtures/snapbi_test_public.pem'));

    // Routes are loaded during boot, so enabling the route in config afterwards
    // is not enough — it has to be declared here as well.
    app('router')->post(SNAP_BI_PATH, SnapBiWebhookController::class)
        ->middleware(VerifySnapBiSignature::class);
});

/**
 * Signs a body the way Midtrans signs a Snap-BI notification:
 * HTTPMethod + ":" + path + ":" + sha256(body) + ":" + timestamp
 *
 * @return array{0: string, 1: array<string, string>}
 */
function snapBiSigned(array $payload, ?string $timestamp = null): array
{
    $body = (string) json_encode($payload);
    $timestamp ??= gmdate('c');

    $stringToSign = 'POST:'.SNAP_BI_PATH.':'.strtolower(hash('sha256', $body)).':'.$timestamp;

    openssl_sign(
        $stringToSign,
        $signature,
        (string) file_get_contents(__DIR__.'/../Fixtures/snapbi_test_private.pem'),
        OPENSSL_ALGO_SHA256,
    );

    return [$body, [
        'X-SIGNATURE' => base64_encode($signature),
        'X-TIMESTAMP' => $timestamp,
        'Content-Type' => 'application/json',
    ]];
}

it('accepts a correctly signed notification', function () {
    Event::fake([SnapBiWebhookReceived::class]);

    [$body, $headers] = snapBiSigned([
        'originalPartnerReferenceNo' => 'REF-1001',
        'originalReferenceNo' => 'MID-2001',
        'latestTransactionStatus' => '00',
        'amount' => ['value' => '10000.00', 'currency' => 'IDR'],
    ]);

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: $body)
        ->assertOk()
        ->assertJson(['responseCode' => '2000000']);

    Event::assertDispatched(
        SnapBiWebhookReceived::class,
        fn (SnapBiWebhookReceived $event): bool => $event->notification->partnerReferenceNo() === 'REF-1001'
            && $event->notification->isSettled(),
    );
});

it('verifies against the raw body, so an empty additionalInfo object still passes', function () {
    Event::fake([SnapBiWebhookReceived::class]);

    // Re-encoding a decoded array turns {} into [] and the hash stops matching.
    // Snap-BI payloads carry this shape routinely.
    [$body, $headers] = snapBiSigned([
        'originalPartnerReferenceNo' => 'REF-1002',
        'latestTransactionStatus' => '00',
        'additionalInfo' => new stdClass,
    ]);

    expect($body)->toContain('"additionalInfo":{}');

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: $body)->assertOk();

    Event::assertDispatched(SnapBiWebhookReceived::class);
});

it('rejects a body that changed after signing', function () {
    Event::fake([SnapBiWebhookReceived::class]);

    [, $headers] = snapBiSigned(['latestTransactionStatus' => '00']);

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: '{"latestTransactionStatus":"06"}')
        ->assertForbidden();

    Event::assertNotDispatched(SnapBiWebhookReceived::class);
});

it('rejects a replayed notification outside the tolerance window', function () {
    Event::fake([SnapBiWebhookReceived::class]);

    [$body, $headers] = snapBiSigned(['latestTransactionStatus' => '00'], gmdate('c', time() - 3600));

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: $body)->assertForbidden();

    Event::assertNotDispatched(SnapBiWebhookReceived::class);
});

it('accepts an old notification when the window check is disabled', function () {
    Event::fake([SnapBiWebhookReceived::class]);
    config()->set('midtrans.snap_bi_webhook.timestamp_tolerance', null);

    [$body, $headers] = snapBiSigned(['latestTransactionStatus' => '00'], gmdate('c', time() - 3600));

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: $body)->assertOk();

    Event::assertDispatched(SnapBiWebhookReceived::class);
});

it('rejects everything when no public key is configured', function () {
    config()->set('midtrans.snap_bi.public_key', '');

    [$body, $headers] = snapBiSigned(['latestTransactionStatus' => '00']);

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: $body)->assertForbidden();
});

it('maps the BI-SNAP status codes', function (string $code, string $predicate) {
    $notification = SnapBiNotification::fromArray(['latestTransactionStatus' => $code]);

    expect($notification->{$predicate}())->toBeTrue();
})->with([
    ['00', 'isSettled'],
    ['01', 'isPending'],
    ['02', 'isPending'],
    ['03', 'isPending'],
    ['04', 'isRefunded'],
    ['05', 'isCancelled'],
    ['06', 'isFailed'],
    ['07', 'isFailed'],
]);

it('reads the amount without casting it', function () {
    $notification = SnapBiNotification::fromArray([
        'amount' => ['value' => '10000.00', 'currency' => 'IDR'],
    ]);

    expect($notification->amount())->toBe('10000.00')->toBeString()
        ->and($notification->currency())->toBe('IDR');
});

it('rejects a Snap-BI notification with an empty body', function () {
    Event::fake([SnapBiWebhookReceived::class]);

    [, $headers] = snapBiSigned(['latestTransactionStatus' => '00']);

    $this->call('POST', SNAP_BI_PATH, server: $this->transformHeadersToServerVars($headers), content: '')
        ->assertForbidden();

    Event::assertNotDispatched(SnapBiWebhookReceived::class);
});
