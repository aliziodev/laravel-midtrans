<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * The transaction failed inside Midtrans or at the payment provider.
 */
final class PaymentFailed extends PaymentEvent {}
