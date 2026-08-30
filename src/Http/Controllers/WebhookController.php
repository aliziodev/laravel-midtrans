<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Http\Controllers;

use Aliziodev\LaravelMidtrans\Webhook\Notification;
use Aliziodev\LaravelMidtrans\Webhook\WebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Midtrans HTTP notifications.
 *
 * The signature check happens in middleware, so anything reaching here is
 * authentic. Listeners do the work: this only answers Midtrans.
 */
final class WebhookController
{
    public function __invoke(Request $request, WebhookHandler $handler): JsonResponse
    {
        $rawBody = (string) $request->getContent();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING) ?: [];

        $notification = $handler->handle(Notification::fromArray($payload), $rawBody);

        // Midtrans redelivers until it sees a 2xx. Anything a listener throws
        // becomes a 500 and earns a redelivery, which is what you want.
        return new JsonResponse([
            'order_id' => $notification->orderId(),
            'transaction_status' => $notification->transactionStatus(),
        ]);
    }
}
