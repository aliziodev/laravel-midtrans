<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The customer has been given payment instructions but has not paid yet.
 *
 * A virtual account number was issued, a QR was generated, or the customer was
 * redirected. Nothing has been paid: do not fulfil on this.
 */
final class PaymentPending extends PaymentEvent {}
