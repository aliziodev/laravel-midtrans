<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Webhook;

use Aliziodev\LaravelMidtrans\Events\PaymentAuthorized;
use Aliziodev\LaravelMidtrans\Events\PaymentCancelled;
use Aliziodev\LaravelMidtrans\Events\PaymentChallenged;
use Aliziodev\LaravelMidtrans\Events\PaymentChargedBack;
use Aliziodev\LaravelMidtrans\Events\PaymentDenied;
use Aliziodev\LaravelMidtrans\Events\PaymentEvent;
use Aliziodev\LaravelMidtrans\Events\PaymentExpired;
use Aliziodev\LaravelMidtrans\Events\PaymentFailed;
use Aliziodev\LaravelMidtrans\Events\PaymentPending;
use Aliziodev\LaravelMidtrans\Events\PaymentRefunded;
use Aliziodev\LaravelMidtrans\Events\PaymentSettled;
use Aliziodev\LaravelMidtrans\Events\WebhookReceived;
use Aliziodev\MidtransPhp\MidtransClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

final class WebhookHandler
{
    public function __construct(
        private readonly MidtransClient $client,
        private readonly Dispatcher $events,
        private readonly CacheFactory $cache,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Turns a verified notification into the matching payment event.
     *
     * Returns the notification the events were built from, which is the status
     * read back from the API unless that has been switched off.
     */
    public function handle(Notification $received, string $rawBody): Notification
    {
        $this->events->dispatch(new WebhookReceived($received, $rawBody));

        $notification = $this->authoritative($received);

        if (! $this->claim($notification)) {
            $this->log('info', 'Midtrans webhook skipped as a duplicate delivery', $notification);

            return $notification;
        }

        $event = $this->eventFor($notification);

        if ($event === null) {
            $this->log('info', 'Midtrans webhook carried a status with no matching event', $notification);

            return $notification;
        }

        $this->events->dispatch(new $event($notification));
        $this->log('info', 'Midtrans payment event dispatched', $notification, ['event' => $event]);

        return $notification;
    }

    /**
     * A valid signature proves the notification is authentic, not current. The
     * status is read back from the API so listeners act on the transaction as it
     * stands now, which also defeats a replay of an older notification.
     *
     * Failures propagate: Midtrans retries a webhook until it gets a 2xx, so a
     * transient error costs a redelivery rather than a lost payment. Silently
     * falling back to the payload would defeat the point of the check.
     */
    private function authoritative(Notification $received): Notification
    {
        if (! $this->config->get('midtrans.webhook.verify_with_api', true)) {
            return $received;
        }

        // transaction_id over order_id: an order id can be reused across payment
        // attempts, and Midtrans has shipped statuses where order_id disagreed
        // with the notification (Midtrans/midtrans-php#113).
        $id = $received->transactionId() !== ''
            ? $received->transactionId()
            : $received->orderId();

        return Notification::fromArray($this->client->getTransactionStatus($id));
    }

    /**
     * Midtrans redelivers a notification until it sees a 2xx, so the same status
     * can arrive several times. Cache::add is atomic, so concurrent deliveries
     * cannot both win the claim.
     */
    private function claim(Notification $notification): bool
    {
        $ttl = (int) $this->config->get('midtrans.webhook.deduplicate.ttl', 300);

        if ($ttl <= 0) {
            return true;
        }

        $key = $this->config->get('midtrans.webhook.deduplicate.prefix', 'midtrans:webhook:')
            .$notification->transactionId()
            .':'.$notification->transactionStatus()
            .':'.($notification->fraudStatus() ?? '-');

        return $this->cache
            ->store($this->config->get('midtrans.webhook.deduplicate.store'))
            ->add($key, true, $ttl);
    }

    /**
     * @return class-string<PaymentEvent>|null
     */
    private function eventFor(Notification $notification): ?string
    {
        // Fraud review outranks the transaction status: a captured payment under
        // challenge is money held, not money earned.
        if ($notification->isChallenged()) {
            return PaymentChallenged::class;
        }

        return match ($notification->transactionStatus()) {
            'settlement' => PaymentSettled::class,
            'capture' => $notification->fraudStatus() === 'deny'
                ? PaymentDenied::class
                : PaymentSettled::class,
            'pending' => PaymentPending::class,
            'authorize' => PaymentAuthorized::class,
            'deny' => PaymentDenied::class,
            'cancel' => PaymentCancelled::class,
            'expire' => PaymentExpired::class,
            'failure' => PaymentFailed::class,
            'refund', 'partial_refund' => PaymentRefunded::class,
            'chargeback', 'partial_chargeback' => PaymentChargedBack::class,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $level, string $message, Notification $notification, array $extra = []): void
    {
        if (! $this->config->get('midtrans.logging.enabled', true)) {
            return;
        }

        // Only identifiers and status: the payload carries customer PII.
        $this->logger->log($level, $message, $notification->loggableContext() + $extra);
    }
}
