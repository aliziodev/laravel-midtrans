<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * A refund was accepted, in full or in part.
 *
 * Since 16 March 2026 card schemes require real-time issuer authorisation for
 * refunds, so a refund request can also be denied — check the status rather
 * than assuming a request means the money moved.
 */
final class PaymentRefunded extends PaymentEvent {}
