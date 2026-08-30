<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

use Aliziodev\LaravelMidtrans\Webhook\Notification;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched for every notification that passes signature verification, before
 * any status-specific event and before deduplication.
 *
 * Unlike the typed events, this carries the webhook body as Midtrans sent it,
 * without the status re-read. Use it for auditing and for statuses the package
 * does not map to an event of their own; use the typed events to act on money.
 */
final class WebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Notification $notification,
        public readonly string $rawBody,
    ) {}
}
