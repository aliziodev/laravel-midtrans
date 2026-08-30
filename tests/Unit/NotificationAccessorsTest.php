<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Webhook\Notification;
use Aliziodev\LaravelMidtrans\Webhook\SnapBiNotification;

/**
 * The accessors an application reaches for once it starts doing real work with
 * a notification, rather than only checking whether it settled.
 */
it('exposes the status code and message', function () {
    $notification = Notification::fromArray([
        'status_code' => '201',
        'status_message' => 'Success, Bank Transfer transaction is created',
    ]);

    expect($notification->statusCode())->toBe('201')
        ->and($notification->statusMessage())->toContain('Bank Transfer');
});

it('exposes the signature key for callers verifying it themselves', function () {
    expect(Notification::fromArray(['signature_key' => 'abc123'])->signatureKey())->toBe('abc123')
        ->and(Notification::fromArray([])->signatureKey())->toBeNull();
});

it('recognises a pending transaction', function () {
    expect(Notification::fromArray(['transaction_status' => 'pending'])->isPending())->toBeTrue()
        ->and(Notification::fromArray(['transaction_status' => 'settlement'])->isPending())->toBeFalse();
});

it('reaches nested fields by dot path', function () {
    $notification = Notification::fromArray([
        'va_numbers' => [['bank' => 'bca', 'va_number' => '12345678']],
    ]);

    expect($notification->get('va_numbers.0.bank'))->toBe('bca')
        ->and($notification->get('va_numbers.0.va_number'))->toBe('12345678')
        ->and($notification->get('missing.path', 'fallback'))->toBe('fallback');
});

it('hands back the whole payload three equivalent ways', function () {
    $payload = ['order_id' => 'ORDER-1', 'custom_field1' => 'anything'];
    $notification = Notification::fromArray($payload);

    expect($notification->raw())->toBe($payload)
        ->and($notification->toArray())->toBe($payload)
        ->and($notification->jsonSerialize())->toBe($payload)
        // Serialising the event for a queue must not lose fields the SDK does
        // not model.
        ->and(json_decode((string) json_encode($notification), true))->toBe($payload);
});

it('exposes the Snap-BI references and descriptions', function () {
    $notification = SnapBiNotification::fromArray([
        'originalReferenceNo' => 'MID-2001',
        'latestTransactionStatus' => '00',
        'transactionStatusDesc' => 'Success',
        'responseCode' => '2005400',
        'responseMessage' => 'Successful',
    ]);

    expect($notification->referenceNo())->toBe('MID-2001')
        ->and($notification->transactionStatusDesc())->toBe('Success')
        ->and($notification->responseCode())->toBe('2005400')
        ->and($notification->responseMessage())->toBe('Successful');
});

it('falls back to the non-original Snap-BI reference fields', function () {
    // A create response carries partnerReferenceNo; a notification about it
    // carries originalPartnerReferenceNo. Both have to read the same way.
    $notification = SnapBiNotification::fromArray([
        'partnerReferenceNo' => 'REF-1',
        'referenceNo' => 'MID-1',
    ]);

    expect($notification->partnerReferenceNo())->toBe('REF-1')
        ->and($notification->referenceNo())->toBe('MID-1');
});

it('reaches nested Snap-BI fields and returns the raw payload', function () {
    $payload = [
        'latestTransactionStatus' => '00',
        'additionalInfo' => ['channel' => 'gopay'],
    ];
    $notification = SnapBiNotification::fromArray($payload);

    expect($notification->get('additionalInfo.channel'))->toBe('gopay')
        ->and($notification->get('nope', 'default'))->toBe('default')
        ->and($notification->raw())->toBe($payload)
        ->and($notification->toArray())->toBe($payload)
        ->and($notification->jsonSerialize())->toBe($payload);
});

it('keeps the Snap-BI log context to identifiers and status', function () {
    $context = SnapBiNotification::fromArray([
        'originalPartnerReferenceNo' => 'REF-1',
        'originalReferenceNo' => 'MID-1',
        'latestTransactionStatus' => '00',
        'responseCode' => '2005400',
        'additionalInfo' => ['customerName' => 'Someone Real'],
    ])->loggableContext();

    expect($context)->toHaveKeys(['partner_reference_no', 'reference_no', 'latest_transaction_status', 'response_code'])
        ->and(json_encode($context))->not->toContain('Someone Real');
});

it('reports a missing Snap-BI amount as null rather than guessing', function () {
    expect(SnapBiNotification::fromArray([])->amount())->toBeNull()
        ->and(SnapBiNotification::fromArray([])->currency())->toBe('IDR');
});
