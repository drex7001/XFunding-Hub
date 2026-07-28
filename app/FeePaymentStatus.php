<?php

namespace App;

enum FeePaymentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case RefundReview = 'refund_review';
    case Refunded = 'refunded';

    public function label(): string
    {
        return $this === self::RefundReview ? 'Refund review' : ucfirst($this->value);
    }
}
