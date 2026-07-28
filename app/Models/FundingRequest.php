<?php

namespace App\Models;

use App\BankStatus;
use App\FundingRequestStatus;
use Database\Factories\FundingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['bank_id', 'created_by', 'title', 'amount_minor', 'currency', 'tenor_days', 'settlement_date', 'bidding_deadline', 'day_count', 'rate_basis', 'borrower_background'])]
class FundingRequest extends Model
{
    /** @use HasFactory<FundingRequestFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tenor_days' => 'integer',
            'version' => 'integer',
            'settlement_date' => 'immutable_date',
            'bidding_deadline' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'borrower_profile' => 'array',
            'contributor_ids' => 'array',
            'status' => FundingRequestStatus::class,
        ];
    }

    protected function amount(): Attribute
    {
        return Attribute::make(get: fn (): string => self::formatMinor($this->amount_minor));
    }

    public static function formatMinor(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function currentStatus(): FundingRequestStatus
    {
        if (in_array($this->status, [FundingRequestStatus::Open, FundingRequestStatus::PendingApproval], true) && ! $this->bidding_deadline->isFuture()) {
            return FundingRequestStatus::Expired;
        }

        return $this->status;
    }

    public function isEditable(): bool
    {
        return in_array($this->currentStatus(), [FundingRequestStatus::Draft, FundingRequestStatus::PendingApproval], true)
            && ($this->pendingApproval === null || $this->pendingApproval->kind === 'publish');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FundingRequestStatus::Open->value)
            ->where('bidding_deadline', '>', now())
            ->whereHas('bank', fn (Builder $bank): Builder => $bank->where('status', BankStatus::Verified->value)->where('can_borrow', true));
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FundingDocument::class)->chaperone();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(FundingRequestApproval::class);
    }

    public function pendingApproval(): HasOne
    {
        return $this->hasOne(FundingRequestApproval::class)->where('status', 'pending');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->chaperone();
    }

    public function pendingSelection(): HasOne
    {
        return $this->hasOne(SelectionProposal::class)->where('status', 'pending');
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }
}
