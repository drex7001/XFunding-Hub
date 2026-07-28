<?php

namespace App;

enum PaymentReceiptStatus: string
{
    case Verified = 'verified';
    case RefundReview = 'refund_review';
    case Refunded = 'refunded';
}
