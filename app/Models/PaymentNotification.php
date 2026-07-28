<?php

namespace App\Models;

use Database\Factories\PaymentNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'event_reference', 'payload_sha256', 'payment_reference', 'reported_deal_id', 'fee_payment_id', 'payment_receipt_id', 'outcome', 'reason'])]
class PaymentNotification extends Model
{
    /** @use HasFactory<PaymentNotificationFactory> */
    use HasFactory, HasUlids;
}
