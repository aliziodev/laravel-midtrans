<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

use Aliziodev\LaravelMidtrans\Webhook\Notification;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for every payment event.
 *
 * The notification carried here has, by default, been re-read from the status
 * API rather than trusted from the webhook body, so listeners are acting on the
 * transaction's current state instead of a payload that may be replayed or
 * already stale.
 */
abstract class PaymentEvent
{
    use Dispatchable;

    public function __construct(public readonly Notification $notification) {}

    public function orderId(): string
    {
        return $this->notification->orderId();
    }

    public function transactionId(): string
    {
        return $this->notification->transactionId();
    }
}
