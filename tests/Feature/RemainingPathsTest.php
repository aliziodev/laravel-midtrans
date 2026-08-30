<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Events\PaymentAuthorized;
use Aliziodev\LaravelMidtrans\Events\PaymentChargedBack;
use Aliziodev\LaravelMidtrans\Events\PaymentFailed;
use Aliziodev\LaravelMidtrans\Events\PaymentSettled;
use Aliziodev\LaravelMidtrans\Facades\Midtrans;
use Aliziodev\LaravelMidtrans\Testing\RecordedRequest;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * @param  array<string, mixed>  $payload
 */
function respondWith(array $payload): void
{
    Midtrans::fake(['*/status' => $payload]);
}

it('maps the statuses the earlier suite did not reach', function (string $status, string $event) {
    Event::fake([$event]);

    $payload = $this->signedPayload(['transaction_status' => $status, 'fraud_status' => null]);
    respondWith($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatched($event);
})->with([
    'authorize' => ['authorize', PaymentAuthorized::class],
    'failure' => ['failure', PaymentFailed::class],
    'chargeback' => ['chargeback', PaymentChargedBack::class],
    'partial chargeback' => ['partial_chargeback', PaymentChargedBack::class],
]);

it('falls back to the order id when the notification carries no transaction id', function () {
    Event::fake([PaymentSettled::class]);

    // Not every payment type sends transaction_id in the notification; the
    // status re-read still has to be addressable.
    $payload = $this->signedPayload(['transaction_id' => '']);
    respondWith($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Midtrans::assertSent(fn (RecordedRequest $r): bool => str_contains($r->url, '/v2/ORDER-1001/status'));
    Event::assertDispatched(PaymentSettled::class);
});

it('stays silent when logging is switched off', function () {
    config()->set('midtrans.logging.enabled', false);

    // The package resolves its logger through log->channel(), so the spy has to
    // be what that call returns rather than the manager itself.
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->andReturn($logger);

    $payload = $this->signedPayload();
    respondWith($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    $logger->shouldNotHaveReceived('log');
});

it('logs the dispatched event when logging is on', function () {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->andReturn($logger);

    $payload = $this->signedPayload();
    respondWith($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    $logger->shouldHaveReceived('log')
        ->withArgs(fn (string $level, string $message, array $context): bool => $message === 'Midtrans payment event dispatched'
            && $context['order_id'] === 'ORDER-1001');
});

it('exposes the event transaction id to listeners', function () {
    Event::fake([PaymentSettled::class]);

    $payload = $this->signedPayload();
    respondWith($payload);

    $this->postJson('/midtrans/webhook', $payload)->assertOk();

    Event::assertDispatched(
        PaymentSettled::class,
        fn (PaymentSettled $event): bool => $event->transactionId() === 'trx-0001',
    );
});

it('reports a header the request never carried as null', function () {
    Midtrans::fake();

    Midtrans::getTransactionStatus('ORDER-1');

    Midtrans::assertSent(fn (RecordedRequest $r): bool => $r->header('X-Never-Sent') === null
        && $r->header('authorization') !== null);
});

it('treats a request with no body as having no data', function () {
    Midtrans::fake();

    // A GET carries no body; reading it must not blow up in an assertion.
    Midtrans::getTransactionStatus('ORDER-1');

    Midtrans::assertSent(fn (RecordedRequest $r): bool => $r->data() === []
        && $r->input('anything') === null);
});

it('accepts a prepared HttpResponse as a fake, not just an array', function () {
    // Lets a test script an exact status code, which an array cannot express.
    Midtrans::fake(['*/status' => new HttpResponse(418, '{"transaction_status":"teapot"}')]);

    expect(fn () => Midtrans::getTransactionStatus('ORDER-1'))
        ->toThrow(MidtransApiException::class);
});
