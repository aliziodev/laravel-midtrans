<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The customer disputed the payment with their issuer and the funds were pulled back.
 */
final class PaymentChargedBack extends PaymentEvent {}
