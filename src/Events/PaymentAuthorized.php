<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * A card pre-authorisation succeeded and funds are held, not captured.
 *
 * Capture it to take the money, or it will be released when the hold expires.
 */
final class PaymentAuthorized extends PaymentEvent {}
