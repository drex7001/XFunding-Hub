<?php

namespace App\Models;

use App\FeePaymentStatus;
use App\PaymentReceiptStatus;
use Database\Factories\FeePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['deal_id', 'bank_id', 'initiated_by', 'provider', 'provider_reference', 'amount_minor', 'currency', 'pending_slot'])]
#[Hidden(['initiated_by', 'pending_slot'])]
class FeePayment extends Model
{
    /** @use HasFactory<FeePaymentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['status' => FeePaymentStatus::class, 'amount_minor' => 'integer', 'verified_at' => 'immutable_datetime'];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn (): string => FundingRequest::formatMinor($this->amount_minor));
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function verifiedReceipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class)->where('status', PaymentReceiptStatus::Verified);
    }
}
