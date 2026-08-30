<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The payment window closed without payment.
 *
 * Release any stock or reservation held for this order.
 */
final class PaymentExpired extends PaymentEvent {}
