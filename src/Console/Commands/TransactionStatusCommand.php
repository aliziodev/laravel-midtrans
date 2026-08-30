<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Console\Commands;

use Aliziodev\LaravelMidtrans\Webhook\Notification;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\MidtransClient;
use Illuminate\Console\Command;

/**
 * Reads a transaction's current state straight from Midtrans.
 *
 * Useful when a webhook was missed or a listener failed: the API is the source
 * of truth, not the notification you did or did not receive.
 */
final class TransactionStatusCommand extends Command
{
    protected $signature = 'midtrans:status
        {id : The order_id or transaction_id}
        {--json : Print the raw API response instead of a table}';

    protected $description = 'Read the current status of a Midtrans transaction';

    public function handle(MidtransClient $client): int
    {
        /** @var string $id */
        $id = $this->argument('id');

        try {
            $response = $client->getTransactionStatus($id);
        } catch (MidtransApiException $exception) {
            $this->components->error(sprintf(
                'Midtrans returned %d: %s',
                $exception->statusCode,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        } catch (MidtransException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $notification = Notification::fromArray($response);

        $rows = [
            ['Order ID', $notification->orderId()],
            ['Transaction ID', $notification->transactionId()],
            ['Status', $notification->transactionStatus()],
            ['Fraud status', $notification->fraudStatus() ?? '—'],
            ['Payment type', $notification->paymentType()],
            ['Gross amount', $notification->grossAmount().' '.$notification->currency()],
        ];

        // Only worth showing when Automatic Fee Imposition is on and the two differ.
        if ($notification->originalAmount() !== $notification->grossAmount()) {
            $rows[] = ['Original amount', (string) $notification->originalAmount()];
            $rows[] = ['Customer fee', (string) $notification->customerImposedFee()];
        }

        $rows[] = ['Transaction time', $notification->transactionTime() ?? '—'];
        $rows[] = ['Settlement time', $notification->settlementTime() ?? '—'];

        $this->table(['Field', 'Value'], $rows);

        $notification->isSettled()
            ? $this->components->info('Settled: this transaction is safe to fulfil.')
            : $this->components->warn('Not settled: do not fulfil on this status.');

        return self::SUCCESS;
    }
}
