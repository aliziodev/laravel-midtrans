<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The transaction was cancelled before settlement, by you or by the customer.
 */
final class PaymentCancelled extends PaymentEvent {}
