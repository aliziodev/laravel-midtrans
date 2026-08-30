<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

use Aliziodev\LaravelMidtrans\Webhook\SnapBiNotification;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched for every Snap-BI notification that passes signature verification.
 *
 * Snap-BI reports state through latestTransactionStatus rather than the Core
 * API's transaction_status, so it gets one event carrying its own payload
 * instead of being mapped onto the PaymentEvent hierarchy. Branch on the
 * notification's isSettled(), isPending() and friends.
 */
final class SnapBiWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly SnapBiNotification $notification,
        public readonly string $rawBody,
    ) {}
}
