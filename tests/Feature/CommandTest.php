<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Facades\Midtrans;

it('prints the current status of a transaction', function () {
    Midtrans::fake(['*/status' => [
        'order_id' => 'ORDER-1001',
        'transaction_id' => 'trx-0001',
        'transaction_status' => 'settlement',
        'fraud_status' => 'accept',
        'payment_type' => 'gopay',
        'gross_amount' => '10000.00',
        'status_code' => '200',
    ]]);

    $this->artisan('midtrans:status', ['id' => 'ORDER-1001'])
        ->expectsOutputToContain('ORDER-1001')
        ->expectsOutputToContain('settlement')
        ->expectsOutputToContain('safe to fulfil')
        ->assertSuccessful();
});

it('warns when the transaction is not settled', function () {
    Midtrans::fake(['*/status' => [
        'order_id' => 'ORDER-1002',
        'transaction_status' => 'pending',
        'gross_amount' => '10000.00',
    ]]);

    $this->artisan('midtrans:status', ['id' => 'ORDER-1002'])
        ->expectsOutputToContain('do not fulfil')
        ->assertSuccessful();
});

it('shows the pre-fee amount when it differs from the charged amount', function () {
    Midtrans::fake(['*/status' => [
        'order_id' => 'ORDER-1003',
        'transaction_status' => 'settlement',
        'gross_amount' => '10071',
        'metadata' => ['extra_info' => ['gross_amount_info' => [
            'original_amount' => '10000',
            'customer_imposed_payment_fee' => '71',
        ]]],
    ]]);

    $this->artisan('midtrans:status', ['id' => 'ORDER-1003'])
        ->expectsOutputToContain('Original amount')
        ->assertSuccessful();
});

it('emits raw json when asked', function () {
    Midtrans::fake(['*/status' => ['order_id' => 'ORDER-1004', 'transaction_status' => 'expire']]);

    $this->artisan('midtrans:status', ['id' => 'ORDER-1004', '--json' => true])
        ->expectsOutputToContain('"transaction_status": "expire"')
        ->assertSuccessful();
});

it('fails with the API message rather than a stack trace', function () {
    $transport = Midtrans::fake();
    $transport->push(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404);

    $this->artisan('midtrans:status', ['id' => 'NOPE'])
        ->expectsOutputToContain("Transaction doesn't exist.")
        ->assertFailed();
});

it('reports a transport failure readably', function () {
    Midtrans::fake()->push('<html>502 Bad Gateway</html>');

    $this->artisan('midtrans:status', ['id' => 'ORDER-1005'])
        ->expectsOutputToContain('invalid JSON response')
        ->assertFailed();
});
