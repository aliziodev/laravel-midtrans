<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Events\PaymentCancelled;
use Aliziodev\LaravelMidtrans\Events\PaymentChallenged;
use Aliziodev\LaravelMidtrans\Events\PaymentDenied;
use Aliziodev\LaravelMidtrans\Events\PaymentExpired;
use Aliziodev\LaravelMidtrans\Events\PaymentPending;
use Aliziodev\LaravelMidtrans\Events\PaymentRefunded;
use Aliziodev\LaravelMidtrans\Events\PaymentSettled;
use Aliziodev\LaravelMidtrans\Events\WebhookReceived;
use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Illuminate\Support\Facades\Event;

/**
 * Answers the webhook's status re-read with whatever the payload says, so tests
 * that are not about the re-read behave as if it were a passthrough.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeStatus(array $payload): void
{
    Midtrans::fake(['*/status' => $payload]);
}

/**
 * Every event this package dispatches. Faking exactly these keeps Laravel's own
 * internal events out of assertNothingDispatched().
 *
 * @return list<class-string>
 */
function packageEvents(): array
{
    return [
        WebhookReceived::class,
        PaymentSettled::class,
        PaymentPending::class,
        PaymentDenied::class,
        PaymentCancelled::class,
        PaymentExpired::class,
        PaymentRefunded::class,
        PaymentChallenged::class,
    ];
}

it('dispatches a settled event for a signed settlement notification', function () {
    Event::fake([PaymentSettled::class, WebhookReceived::class]);

    $payload = $this->signedPayload();
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)
        ->assertOk()
        ->assertJson(['order_id' => 'ORDER-1001', 'transaction_status' => 'settlement']);

    Event::assertDispatched(
        PaymentSettled::class,
        fn (PaymentSettled $event): bool => $event->orderId() === 'ORDER-1001'
            && $event->notification->grossAmount() === '10000.00',
    );
    Event::assertDispatched(WebhookReceived::class);
});

it('rejects a notification whose signature does not match', function () {
    Event::fake(packageEvents());

    $payload = $this->signedPayload();
    $payload['signature_key'] = str_repeat('0', 128);

    $this->postJson('/midtrans/webhook', $payload)->assertForbidden();

    Event::assertNothingDispatched();
});

it('rejects a notification whose amount was tampered with after signing', function () {
    Event::fake(packageEvents());

    // The signature covers gross_amount, so raising it invalidates the payload.
    $payload = $this->signedPayload();
    $payload['gross_amount'] = '99999.00';

    $this->postJson('/midtrans/webhook', $payload)->assertForbidden();

    Event::assertNothingDispatched();
});

it('rejects an empty body rather than failing on a missing array key', function () {
    Event::fake(packageEvents());

    // The official SDK reads php://input in a constructor and throws
    // "Trying to access array offset on value of type null" here.
    $this->call('POST', '/midtrans/webhook', server: ['CONTENT_TYPE' => 'application/json'])
        ->assertForbidden();

    Event::assertNothingDispatched();
});

it('rejects everything when the server key is not configured', function () {
    Event::fake(packageEvents());
    config()->set('midtrans.server_key', '');

    $this->postJson('/midtrans/webhook', $this->signedPayload())->assertForbidden();

    Event::assertNothingDispatched();
});

it('acts on the status read back from the API, not on the payload', function () {
    Event::fake([PaymentSettled::class, PaymentExpired::class]);

    // A replayed "settlement" notification for a transaction that has since
    // expired must not fulfil the order.
    $payload = $this->signedPayload(['transaction_status' => 'settlement']);
    Midtrans::fake(['*/status' => ['transaction_status' => 'expire'] + $payload]);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertNotDispatched(PaymentSettled::class);
    Event::assertDispatched(PaymentExpired::class);
});

it('queries the status endpoint by transaction id', function () {
    // order_id can be reused across attempts, and Midtrans has shipped
    // responses where it disagreed with the notification.
    $payload = $this->signedPayload();
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Midtrans::assertSent(fn ($request): bool => str_contains($request->url, '/v2/trx-0001/status'));
});

it('trusts the payload when the API re-read is switched off', function () {
    Event::fake([PaymentSettled::class]);
    config()->set('midtrans.webhook.verify_with_api', false);

    $transport = Midtrans::fake();

    $this->postJson('/midtrans/webhook', $this->signedPayload())->assertOk();

    Event::assertDispatched(PaymentSettled::class);
    expect($transport->recorded())->toBeEmpty();
});

it('dispatches only once when Midtrans redelivers the same notification', function () {
    Event::fake([PaymentSettled::class]);
    config()->set('midtrans.webhook.deduplicate.ttl', 300);

    $payload = $this->signedPayload();
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();
    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatchedTimes(PaymentSettled::class, 1);
});

it('still dispatches when the same transaction moves to a new status', function () {
    Event::fake([PaymentPending::class, PaymentSettled::class]);
    config()->set('midtrans.webhook.deduplicate.ttl', 300);

    $pending = $this->signedPayload(['transaction_status' => 'pending', 'fraud_status' => null]);
    fakeStatus($pending);
    $this->postJson('/midtrans/webhook', $pending)->assertOk();

    $settled = $this->signedPayload(['transaction_status' => 'settlement']);
    fakeStatus($settled);
    $this->postJson('/midtrans/webhook', $settled)->assertOk();

    Event::assertDispatched(PaymentPending::class);
    Event::assertDispatched(PaymentSettled::class);
});

it('maps each transaction status to its event', function (array $status, string $event) {
    Event::fake([$event]);

    $payload = $this->signedPayload($status);
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatched($event);
})->with([
    'settlement' => [['transaction_status' => 'settlement'], PaymentSettled::class],
    'capture accepted' => [['transaction_status' => 'capture', 'fraud_status' => 'accept'], PaymentSettled::class],
    'capture challenged' => [['transaction_status' => 'capture', 'fraud_status' => 'challenge'], PaymentChallenged::class],
    'capture denied' => [['transaction_status' => 'capture', 'fraud_status' => 'deny'], PaymentDenied::class],
    'pending' => [['transaction_status' => 'pending'], PaymentPending::class],
    'deny' => [['transaction_status' => 'deny'], PaymentDenied::class],
    'cancel' => [['transaction_status' => 'cancel'], PaymentCancelled::class],
    'expire' => [['transaction_status' => 'expire'], PaymentExpired::class],
    'refund' => [['transaction_status' => 'refund'], PaymentRefunded::class],
    'partial refund' => [['transaction_status' => 'partial_refund'], PaymentRefunded::class],
]);

it('accepts a status it has no event for without failing', function () {
    Event::fake([WebhookReceived::class]);

    $payload = $this->signedPayload(['transaction_status' => 'something_new']);
    fakeStatus($payload);

    // A 2xx stops Midtrans redelivering. Failing on an unknown status would
    // make it retry forever.
    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatched(WebhookReceived::class);
});

it('hands listeners the untouched body on WebhookReceived', function () {
    Event::fake([WebhookReceived::class]);

    $payload = $this->signedPayload();
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatched(
        WebhookReceived::class,
        fn (WebhookReceived $event): bool => json_decode($event->rawBody, true) === $payload,
    );
});

it('registers the webhook route by default', function () {
    expect(app('router')->getRoutes()->getByName('midtrans.webhook'))->not->toBeNull();
});

it('exposes the middleware under an alias for hand-written routes', function () {
    expect(app('router')->getMiddleware())
        ->toHaveKey('midtrans.signature')
        ->toHaveKey('midtrans.snap-bi.signature');
});

it('is not behind the web middleware group, so no CSRF token is needed', function () {
    $route = app('router')->getRoutes()->getByName('midtrans.webhook');

    expect($route->gatherMiddleware())->not->toContain('web');
});

it('reports the outcome back to Midtrans', function () {
    $payload = $this->signedPayload(['transaction_status' => 'expire']);
    fakeStatus($payload);

    $this->postJson('/midtrans/webhook', $payload)
        ->assertOk()
        ->assertExactJson(['order_id' => 'ORDER-1001', 'transaction_status' => 'expire']);
});

it('lets a failing listener surface so Midtrans redelivers', function () {
    $payload = $this->signedPayload();
    fakeStatus($payload);

    Event::listen(PaymentSettled::class, function (): void {
        throw new RuntimeException('the listener blew up');
    });

    $this->withoutExceptionHandling()
        ->postJson('/midtrans/webhook', $payload);
})->throws(RuntimeException::class, 'the listener blew up');

it('leaves the Snap-BI route unregistered until it is switched on', function () {
    // snap_bi_webhook.enabled defaults to false, so the absence of this route
    // is what proves the config flag is honoured at registration time.
    expect(config('midtrans.snap_bi_webhook.enabled'))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('midtrans.snap-bi.webhook'))->toBeNull();
});
