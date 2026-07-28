<?php

namespace App\Models;

use App\BankStatus;
use App\FundingRequestStatus;
use App\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['funding_request_id', 'bank_id', 'created_by', 'settlement_instruction_id', 'rate_units', 'collateral', 'interest_payment', 'valid_until'])]
#[Hidden(['bank_id', 'created_by', 'contributor_ids', 'settlement_instruction_id', 'borrower_token'])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::creating(function (Offer $offer): void {
            $offer->borrower_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['status' => OfferStatus::class, 'rate_units' => 'integer', 'version' => 'integer', 'label_number' => 'integer', 'contributor_ids' => 'array', 'valid_until' => 'immutable_datetime', 'activated_at' => 'immutable_datetime'];
    }

    protected function rate(): Attribute
    {
        return Attribute::make(get: fn (): string => intdiv($this->rate_units, 10000).'.'.str_pad((string) ($this->rate_units % 10000), 4, '0', STR_PAD_LEFT));
    }

    public static function rateToUnits(string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    }

    public function currentStatus(): OfferStatus
    {
        if (in_array($this->status, [OfferStatus::Active, OfferStatus::PendingApproval], true)) {
            if (! $this->valid_until->isFuture() || $this->fundingRequest->currentStatus() === FundingRequestStatus::Expired) {
                return OfferStatus::Expired;
            }
            if ($this->fundingRequest->status === FundingRequestStatus::Cancelled) {
                return OfferStatus::Unsuccessful;
            }
        }

        return $this->status;
    }

    public function isSelectable(): bool
    {
        return $this->status === OfferStatus::Active && $this->valid_until->isFuture()
            && $this->fundingRequest->currentStatus() === FundingRequestStatus::Open
            && $this->fundingRequest->bank->status === BankStatus::Verified && $this->fundingRequest->bank->can_borrow
            && $this->bank->status === BankStatus::Verified && $this->bank->can_lend
            && $this->bank_id !== $this->fundingRequest->bank_id
            && $this->settlementInstruction->bank_id === $this->bank_id
            && $this->settlementInstruction->status === 'approved' && $this->settlementInstruction->matchesBankProfile();
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function fundingRequest(): BelongsTo
    {
        return $this->belongsTo(FundingRequest::class);
    }

    public function settlementInstruction(): BelongsTo
    {
        return $this->belongsTo(SettlementInstruction::class);
    }

    public function pendingApproval(): HasOne
    {
        return $this->hasOne(OfferApproval::class)->where('status', 'pending');
    }
}
