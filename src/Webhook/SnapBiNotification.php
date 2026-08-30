<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Webhook;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A Snap-BI notification, which is shaped nothing like the Core API one.
 *
 * Kept as its own type rather than forced into {@see Notification}: the fields
 * differ, and pretending otherwise would mean guessing at mappings that
 * Midtrans does not define.
 *
 * @implements Arrayable<string, mixed>
 */
final class SnapBiNotification implements Arrayable, JsonSerializable
{
    /**
     * latestTransactionStatus values from the BI-SNAP specification.
     */
    public const STATUS_SUCCESS = '00';

    public const STATUS_INITIATED = '01';

    public const STATUS_PAYING = '02';

    public const STATUS_PENDING = '03';

    public const STATUS_REFUNDED = '04';

    public const STATUS_CANCELLED = '05';

    public const STATUS_FAILED = '06';

    public const STATUS_NOT_FOUND = '07';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * The reference you supplied when creating the payment.
     */
    public function partnerReferenceNo(): string
    {
        return $this->string('originalPartnerReferenceNo') ?: $this->string('partnerReferenceNo');
    }

    /**
     * Midtrans's own reference for the transaction.
     */
    public function referenceNo(): string
    {
        return $this->string('originalReferenceNo') ?: $this->string('referenceNo');
    }

    public function latestTransactionStatus(): string
    {
        return $this->string('latestTransactionStatus');
    }

    public function transactionStatusDesc(): string
    {
        return $this->string('transactionStatusDesc');
    }

    public function responseCode(): string
    {
        return $this->string('responseCode');
    }

    public function responseMessage(): string
    {
        return $this->string('responseMessage');
    }

    /**
     * The amount as Midtrans wrote it, for example "10000.00".
     */
    public function amount(): ?string
    {
        $value = data_get($this->payload, 'amount.value');

        return is_scalar($value) ? (string) $value : null;
    }

    public function currency(): string
    {
        $value = data_get($this->payload, 'amount.currency');

        return is_scalar($value) ? (string) $value : 'IDR';
    }

    public function isSettled(): bool
    {
        return $this->latestTransactionStatus() === self::STATUS_SUCCESS;
    }

    public function isPending(): bool
    {
        return in_array(
            $this->latestTransactionStatus(),
            [self::STATUS_INITIATED, self::STATUS_PAYING, self::STATUS_PENDING],
            true,
        );
    }

    public function isRefunded(): bool
    {
        return $this->latestTransactionStatus() === self::STATUS_REFUNDED;
    }

    public function isCancelled(): bool
    {
        return $this->latestTransactionStatus() === self::STATUS_CANCELLED;
    }

    public function isFailed(): bool
    {
        return in_array(
            $this->latestTransactionStatus(),
            [self::STATUS_FAILED, self::STATUS_NOT_FOUND],
            true,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, string>
     */
    public function loggableContext(): array
    {
        return [
            'partner_reference_no' => $this->partnerReferenceNo(),
            'reference_no' => $this->referenceNo(),
            'latest_transaction_status' => $this->latestTransactionStatus(),
            'response_code' => $this->responseCode(),
        ];
    }

    private function string(string $key): string
    {
        $value = $this->payload[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
