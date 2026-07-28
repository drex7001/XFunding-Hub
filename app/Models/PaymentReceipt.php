<?php

namespace App\Models;

use App\PaymentReceiptStatus;
use Database\Factories\PaymentReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fee_payment_id', 'provider', 'charge_reference', 'payment_reference', 'reported_deal_id', 'amount_minor', 'currency', 'reason', 'received_at'])]
class PaymentReceipt extends Model
{
    /** @use HasFactory<PaymentReceiptFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'status' => PaymentReceiptStatus::class, 'received_at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime'];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn (): string => FundingRequest::formatMinor($this->amount_minor));
    }

    public function feePayment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class);
    }
}
