<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The payment was rejected, by the issuer or by fraud detection.
 *
 * Nothing was charged.
 */
final class PaymentDenied extends PaymentEvent {}
