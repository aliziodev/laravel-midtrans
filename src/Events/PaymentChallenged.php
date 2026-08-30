<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Events;

/**
 * Midtrans's fraud detection flagged the transaction for manual review.
 *
 * The money is held. Approve or deny it from the dashboard, or through
 * approveTransaction() and denyTransaction(). It settles only after approval.
 */
final class PaymentChallenged extends PaymentEvent {}
