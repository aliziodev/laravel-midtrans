<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Webhook\Notification;

it('reads the common fields', function () {
    $notification = Notification::fromArray([
        'order_id' => 'ORDER-1',
        'transaction_id' => 'trx-1',
        'transaction_status' => 'settlement',
        'fraud_status' => 'accept',
        'payment_type' => 'bank_transfer',
        'gross_amount' => '10000.00',
        'status_code' => '200',
    ]);

    expect($notification->orderId())->toBe('ORDER-1')
        ->and($notification->transactionId())->toBe('trx-1')
        ->and($notification->transactionStatus())->toBe('settlement')
        ->and($notification->fraudStatus())->toBe('accept')
        ->and($notification->paymentType())->toBe('bank_transfer')
        ->and($notification->currency())->toBe('IDR');
});

it('keeps the amount as the exact string Midtrans sent', function () {
    // The signature is computed over this text. Casting it to a float turns
    // "10000.00" into "10000" and verification starts failing.
    expect(Notification::fromArray(['gross_amount' => '10000.00'])->grossAmount())
        ->toBe('10000.00')
        ->toBeString();
});

it('treats a settlement as settled', function () {
    expect(Notification::fromArray(['transaction_status' => 'settlement'])->isSettled())->toBeTrue();
});

it('treats an accepted card capture as settled but a challenged one as not', function () {
    $accepted = Notification::fromArray(['transaction_status' => 'capture', 'fraud_status' => 'accept']);
    $challenged = Notification::fromArray(['transaction_status' => 'capture', 'fraud_status' => 'challenge']);

    expect($accepted->isSettled())->toBeTrue()
        ->and($challenged->isSettled())->toBeFalse()
        ->and($challenged->isChallenged())->toBeTrue();
});

it('exposes the pre-fee amount when Automatic Fee Imposition is on', function () {
    // With the feature on, gross_amount includes the fee charged to the
    // customer, so reconciling against it reports a false overpayment.
    $notification = Notification::fromArray([
        'gross_amount' => '10071',
        'metadata' => ['extra_info' => ['gross_amount_info' => [
            'original_amount' => '10000',
            'gross_amount' => '10071',
            'customer_imposed_payment_fee' => '71',
        ]]],
    ]);

    expect($notification->grossAmount())->toBe('10071')
        ->and($notification->originalAmount())->toBe('10000')
        ->and($notification->customerImposedFee())->toBe('71');
});

it('falls back to gross amount when there is no fee breakdown', function () {
    expect(Notification::fromArray(['gross_amount' => '10000.00'])->originalAmount())->toBe('10000.00');
});

it('knows which statuses are final', function (string $status, bool $final) {
    expect(Notification::fromArray(['transaction_status' => $status])->isFinal())->toBe($final);
})->with([
    ['settlement', true],
    ['deny', true],
    ['expire', true],
    ['refund', true],
    ['pending', false],
    ['capture', false],
    ['authorize', false],
]);

it('keeps customer details out of the log context', function () {
    $context = Notification::fromArray([
        'order_id' => 'ORDER-1',
        'transaction_id' => 'trx-1',
        'transaction_status' => 'settlement',
        'payment_type' => 'credit_card',
        'masked_card' => '48111111-1114',
        'customer_details' => ['email' => 'buyer@example.com'],
    ])->loggableContext();

    expect($context)->toHaveKeys(['order_id', 'transaction_id', 'transaction_status', 'payment_type'])
        ->and($context)->not->toHaveKey('masked_card')
        ->and($context)->not->toHaveKey('customer_details');
});

it('survives a payload missing everything', function () {
    $notification = Notification::fromArray([]);

    expect($notification->orderId())->toBe('')
        ->and($notification->fraudStatus())->toBeNull()
        ->and($notification->isSettled())->toBeFalse();
});
