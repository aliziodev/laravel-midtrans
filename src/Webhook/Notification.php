<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Webhook;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A typed reading of a Midtrans notification or transaction status payload.
 *
 * Amounts stay strings on purpose. Midtrans sends "10000.00", the signature is
 * computed over that exact text, and casting it to a float turns it into
 * "10000" — which is how signature verification silently starts failing.
 *
 * @implements Arrayable<string, mixed>
 */
final class Notification implements Arrayable, JsonSerializable
{
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

    public function orderId(): string
    {
        return $this->string('order_id');
    }

    public function transactionId(): string
    {
        return $this->string('transaction_id');
    }

    public function transactionStatus(): string
    {
        return $this->string('transaction_status');
    }

    public function fraudStatus(): ?string
    {
        return $this->nullableString('fraud_status');
    }

    public function paymentType(): string
    {
        return $this->string('payment_type');
    }

    public function statusCode(): string
    {
        return $this->string('status_code');
    }

    public function statusMessage(): string
    {
        return $this->string('status_message');
    }

    /**
     * The amount as Midtrans wrote it, for example "10000.00".
     */
    public function grossAmount(): string
    {
        return $this->string('gross_amount');
    }

    /**
     * Amount before Automatic Fee Imposition, when that feature is enabled.
     *
     * With it on, gross_amount includes the fee charged to the customer, so
     * comparing it against your invoice total reports an overpayment. This is
     * the figure to reconcile against.
     *
     * @see https://github.com/Midtrans/midtrans-php/issues/118
     */
    public function originalAmount(): ?string
    {
        return $this->grossAmountInfo('original_amount') ?? $this->nullableString('gross_amount');
    }

    public function customerImposedFee(): ?string
    {
        return $this->grossAmountInfo('customer_imposed_payment_fee');
    }

    public function currency(): string
    {
        return $this->payload['currency'] ?? 'IDR';
    }

    public function transactionTime(): ?string
    {
        return $this->nullableString('transaction_time');
    }

    public function settlementTime(): ?string
    {
        return $this->nullableString('settlement_time');
    }

    public function signatureKey(): ?string
    {
        return $this->nullableString('signature_key');
    }

    public function isSettled(): bool
    {
        return $this->transactionStatus() === 'settlement'
            || ($this->transactionStatus() === 'capture' && $this->fraudStatus() === 'accept');
    }

    public function isPending(): bool
    {
        return $this->transactionStatus() === 'pending';
    }

    public function isChallenged(): bool
    {
        return $this->fraudStatus() === 'challenge';
    }

    /**
     * True for every status the transaction can no longer move out of.
     */
    public function isFinal(): bool
    {
        return in_array(
            $this->transactionStatus(),
            ['settlement', 'deny', 'cancel', 'expire', 'failure', 'refund', 'partial_refund'],
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
     * Safe to log: identifiers and status only, no customer details and no
     * masked card data.
     *
     * @return array<string, string|null>
     */
    public function loggableContext(): array
    {
        return [
            'order_id' => $this->orderId(),
            'transaction_id' => $this->transactionId(),
            'transaction_status' => $this->transactionStatus(),
            'fraud_status' => $this->fraudStatus(),
            'payment_type' => $this->paymentType(),
        ];
    }

    private function grossAmountInfo(string $key): ?string
    {
        $value = data_get($this->payload, 'metadata.extra_info.gross_amount_info.'.$key);

        return is_scalar($value) ? (string) $value : null;
    }

    private function string(string $key): string
    {
        $value = $this->payload[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
