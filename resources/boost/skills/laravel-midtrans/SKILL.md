---
name: laravel-midtrans
description: "Apply this skill whenever writing or reviewing Midtrans payment code in a Laravel application using aliziodev/laravel-midtrans. Triggers for creating Snap tokens or charges, handling payment webhooks and notifications, verifying notification signatures, reacting to transaction status, issuing refunds, reconciling amounts, Snap-BI / BI-SNAP integration, QRIS, virtual accounts, GoPay, and for writing tests that involve Midtrans. Also use when debugging a webhook that is not firing, a 419 or 403 on a notification URL, a signature that will not verify, an order marked paid that was not, or a duplicate charge or refund."
license: MIT
metadata:
  author: aliziodev
---

# Laravel Midtrans

Rules for `aliziodev/laravel-midtrans`, which wraps the `aliziodev/midtrans-php`
SDK. The API surface belongs to the SDK; this package owns configuration,
webhook handling and payment events.

Verify request and response shapes against the Midtrans documentation rather
than inventing fields. This skill covers how to use the package correctly, not
what every endpoint accepts.

## Calling the API

Use the `Midtrans` facade, or inject `Aliziodev\MidtransPhp\MidtransClient`.
Never construct `MidtransConfig` or `MidtransClient` by hand in application
code: the service provider already builds them from config, and a hand-built one
misses the retry policy and the notification headers.

```php
use Aliziodev\LaravelMidtrans\Facades\Midtrans;

$snap = Midtrans::createSnapTransaction([
    'transaction_details' => ['order_id' => $order->id, 'gross_amount' => 150000],
]);
```

Methods read verb-first: `createSnapTransaction`, `chargeTransaction`,
`getTransactionStatus`, `cancelTransaction`, `refundTransaction`. If you recall
`snapCreateTransaction`, `coreCharge`, `transactionStatus`, `cardToken` or
`cardRegister`, those are the 1.x names and no longer exist.

## Never fulfil an order on the webhook payload alone

A signature proves the notification is *authentic*, not *current*. A genuine
notification can be captured and replayed, and the payload can be stale by the
time it arrives.

The package already re-reads the status from the API before dispatching events,
so a listener on `PaymentSettled` is safe. If you write your own handler, do the
same: call `getTransactionStatus()` and act on that.

## Only one event means the money arrived

Listen to `PaymentSettled`. It fires for `settlement`, and for `capture` with
`fraud_status=accept`.

Do not fulfil on `PaymentPending` (instructions issued, nothing paid),
`PaymentAuthorized` (card funds held, not captured) or `PaymentChallenged`
(held for manual fraud review). `PaymentExpired` is where you release stock.

```php
Event::listen(PaymentSettled::class, function (PaymentSettled $event) {
    Order::where('order_id', $event->orderId())->first()?->markAsPaid();
});
```

Let a failing listener throw. The controller returns 500 and Midtrans redelivers,
which is the behaviour you want. Swallowing the exception loses the payment.

## Amounts are strings, and the total may include a fee

`gross_amount` arrives as `"10000.00"`. The signature is computed over that exact
text, so casting it to a float or int turns it into `"10000"` and verification
fails. Keep it a string; compare with `bccomp()` or compare minor units you
derive yourself.

When Automatic Fee Imposition is enabled, `gross_amount` includes the fee charged
to the customer, so reconciling it against your invoice total reports a false
overpayment. Reconcile against `originalAmount()`:

```php
$event->notification->grossAmount();      // "10071" — what was charged
$event->notification->originalAmount();   // "10000" — what you invoiced
$event->notification->customerImposedFee();
```

## Webhooks

The route is registered for you at `POST /midtrans/webhook`, deliberately outside
the `web` middleware group. Do not add it to `web` or attach `VerifyCsrfToken`: a
webhook carries no session and no CSRF token, and that is what produces a 419 that
looks like Midtrans never called.

A 403 from the endpoint means the signature did not verify. Check that
`MIDTRANS_SERVER_KEY` matches the environment the transaction was created in —
a sandbox transaction signed with a production key will never verify.

To point notifications at a tunnel during development without touching the
dashboard, set `MIDTRANS_OVERRIDE_NOTIFICATION_URL`. The SDK sends it as
`X-Override-Notification` on the charge, and Midtrans notifies that URL for that
transaction.

To use your own route, set `MIDTRANS_WEBHOOK_ENABLED=false` and apply the
`midtrans.signature` middleware. Always verify against the raw body
(`$request->getContent()`); an array re-encoded from `$request->all()` does not
reproduce the bytes that were signed.

## Refunds

`refundTransaction()` requires `refund_key` in the payload while retries are
enabled, and will throw without one. This is not ceremony: Midtrans treats a
refund with no `refund_key` as a new refund, so a retried request refunds twice.
Use a value that is stable for that one refund.

Since 16 March 2026 card schemes require real-time issuer authorisation, so a
refund can come back denied. Check the resulting status instead of assuming the
request moved money.

## Idempotency

The SDK generates a fresh `Idempotency-Key` per mutating request. Do not pass one
fixed key for everything: Midtrans replays the cached response for a key across
endpoints for five minutes, so a shared key makes a charge for one order return
another order's response. Use `withIdempotencyKey()` only to repeat one specific
operation, for example after a timeout.

`MIDTRANS_IDEMPOTENCY_PREFIX` is capped at 13 characters, because the whole key
must stay under Midtrans's 46-character limit.

## Testing

Never let a test reach the network. `Midtrans::fake()` swaps the transport and
covers the `SnapBi` facade too, since they share it.

```php
Midtrans::fake([
    '*/snap/v1/transactions' => ['token' => 'snap-token', 'redirect_url' => 'https://pay.test'],
]);

$this->post('/checkout', [...])->assertOk();

Midtrans::assertSent(fn ($request) => $request->input('transaction_details.gross_amount') === 150000);
```

To test your own listeners, dispatch the event directly with
`Notification::fromArray([...])` — no HTTP round trip needed.

## Snap-BI

Separate client, separate payload shape. Status lives in
`latestTransactionStatus` (`"00"` is success), not `transaction_status`, so it
gets its own `SnapBiWebhookReceived` event rather than the `PaymentEvent`
hierarchy.

`X-EXTERNAL-ID` is the only replay protection Snap-BI offers. Generate it with
`Aliziodev\MidtransPhp\SnapBi\ExternalId::generate()` and reuse a value only when
repeating the same logical operation.

## Do not

- Call `cardToken()` or `cardRegister()` from the server. They are deprecated:
  both put the card number and CVV in a URL query string, which is logged by web
  servers and proxies, and pull the application into PCI-DSS SAQ D scope.
  Tokenize in the browser with `midtrans-new-3ds.min.js` and send only the
  resulting `token_id` to the backend.
- Dump the config object with `print_r()` or `var_export()`. `__debugInfo()`
  masks the credentials, and PHP bypasses it for those two functions.
- Log the webhook payload in full. It carries customer PII and, for card flows,
  masked card data. `$notification->loggableContext()` returns the safe subset.
