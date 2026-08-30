<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The money has arrived and the transaction is complete.
 *
 * Dispatched for transaction_status=settlement, and for capture with
 * fraud_status=accept on card payments. This is the only status you should
 * fulfil an order on.
 */
final class PaymentSettled extends PaymentEvent {}
