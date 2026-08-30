<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Http\Controllers;

use Aliziodev\LaravelMidtrans\Events\SnapBiWebhookReceived;
use Aliziodev\LaravelMidtrans\Webhook\SnapBiNotification;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Snap-BI notifications.
 *
 * Answers in the BI-SNAP response envelope: responseCode 2000000 tells Midtrans
 * the notification was accepted and stops redelivery.
 */
final class SnapBiWebhookController
{
    public function __invoke(Request $request, Dispatcher $events): JsonResponse
    {
        $rawBody = (string) $request->getContent();

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING) ?: [];

        $events->dispatch(new SnapBiWebhookReceived(
            SnapBiNotification::fromArray($payload),
            $rawBody,
        ));

        return new JsonResponse([
            'responseCode' => '2000000',
            'responseMessage' => 'Successful',
        ]);
    }
}
