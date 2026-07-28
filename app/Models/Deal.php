<?php

namespace App\Models;

use App\Actions\DemoPaymentProvider;
use App\BankStatus;
use App\DealStatus;
use App\FeePaymentStatus;
use App\FundingRequestStatus;
use App\PaymentReceiptStatus;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['funding_request_id', 'offer_id', 'borrower_bank_id', 'lender_bank_id', 'settlement_instruction_id', 'terms', 'instruction_snapshot', 'offer_valid_until'])]
#[Hidden(['offer_id', 'borrower_bank_id', 'lender_bank_id', 'settlement_instruction_id', 'instruction_snapshot', 'verified_payment_id'])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class, 'terms' => 'array', 'instruction_snapshot' => 'encrypted:array',
            'offer_valid_until' => 'immutable_datetime', 'fee_amount_minor' => 'integer', 'fee_policy' => 'array',
            'fee_quoted_at' => 'immutable_datetime', 'fee_deadline' => 'immutable_datetime', 'unlocked_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'instructions_updated_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime', 'closed_unsettled_at' => 'immutable_datetime',
        ];
    }

    public function currentStatus(): DealStatus
    {
        return $this->status === DealStatus::AwaitingFee && (! $this->offer_valid_until->isFuture() || ($this->fee_deadline !== null && ! $this->fee_deadline->isFuture()))
            ? DealStatus::Cancelled : $this->status;
    }

    public function hasFeeQuote(): bool
    {
        return $this->fee_amount_minor !== null && $this->fee_amount_minor > 0 && $this->fee_currency !== null && $this->fee_policy !== null && $this->fee_deadline !== null;
    }

    public function hasPendingPayment(): bool
    {
        return isset($this->pending_payments_count) ? $this->pending_payments_count > 0 : $this->feePayments()->where('status', FeePaymentStatus::Pending)->exists();
    }

    public function hasPendingRefund(): bool
    {
        return isset($this->pending_refunds_count) ? $this->pending_refunds_count > 0 : $this->receipts()->where('payment_receipts.status', PaymentReceiptStatus::RefundReview)->exists();
    }

    public function banksEligible(): bool
    {
        return $this->borrowerBank->status === BankStatus::Verified && $this->borrowerBank->can_borrow
            && $this->lenderBank->status === BankStatus::Verified && $this->lenderBank->can_lend;
    }

    public function hasApprovedInstructions(): bool
    {
        $instruction = $this->settlementInstruction;
        $snapshot = $this->instruction_snapshot;

        return $instruction !== null && $instruction->bank_id === $this->lender_bank_id && $instruction->status === 'approved'
            && $instruction->matchesBankProfile() && is_array($snapshot)
            && ($snapshot['bank'] ?? null) == $instruction->bank_profile
            && ($snapshot['details'] ?? null) == $instruction->details
            && ($snapshot['version'] ?? null) === $instruction->version;
    }

    public function hasVerifiedFee(): bool
    {
        $payment = $this->verifiedPayment;
        $receipt = $payment?->verifiedReceipt;

        return in_array($this->status, [DealStatus::Unlocked, DealStatus::Disputed, DealStatus::Settled, DealStatus::ClosedUnsettled], true) && $this->unlocked_at !== null && $this->hasFeeQuote()
            && $payment !== null && $payment->deal_id === $this->id && $payment->status === FeePaymentStatus::Verified
            && $payment->verified_at !== null && $payment->amount_minor === $this->fee_amount_minor && $payment->currency === $this->fee_currency
            && $receipt !== null && $receipt->status === PaymentReceiptStatus::Verified && $receipt->accepted_slot === $payment->id
            && $receipt->reported_deal_id === $this->id && $receipt->payment_reference === $payment->provider_reference
            && $receipt->amount_minor === $this->fee_amount_minor && $receipt->currency === $this->fee_currency
            && $payment->provider === 'demo' && $receipt->provider === 'demo' && app(DemoPaymentProvider::class)->enabled();
    }

    public function settlementReady(): bool
    {
        return in_array($this->status, [DealStatus::Unlocked, DealStatus::Disputed], true)
            && $this->fundingRequest->status === FundingRequestStatus::Selected
            && $this->hasVerifiedFee() && $this->banksEligible() && $this->hasApprovedInstructions()
            && $this->pendingInstructionChange === null;
    }

    public function hasBothConfirmations(): bool
    {
        return $this->confirmations->filter(fn (SettlementConfirmation $record): bool => $record->status === 'approved' && $record->settlement_instruction_id === $this->settlement_instruction_id
            && $record->reviewed_at !== null && $record->reviewed_by !== null && $record->reviewed_by !== $record->proposed_by
            && $record->approved_slot === $this->id.':'.$record->bank_id
            && in_array($record->bank_id, [$this->borrower_bank_id, $this->lender_bank_id], true)
        )->pluck('bank_id')->unique()->count() === 2;
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(SettlementConfirmation::class)->chaperone();
    }

    public function activeDispute(): HasOne
    {
        return $this->hasOne(DealDispute::class)->where('status', 'open');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(DealDispute::class)->chaperone();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DealEvent::class)->chaperone();
    }

    public function instructionChanges(): HasMany
    {
        return $this->hasMany(DealInstructionChange::class);
    }

    public function fundingRequest(): BelongsTo
    {
        return $this->belongsTo(FundingRequest::class);
    }

    public function borrowerBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'borrower_bank_id');
    }

    public function lenderBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'lender_bank_id');
    }

    public function settlementInstruction(): BelongsTo
    {
        return $this->belongsTo(SettlementInstruction::class);
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(FeePayment::class)->chaperone();
    }

    public function receipts(): HasManyThrough
    {
        return $this->hasManyThrough(PaymentReceipt::class, FeePayment::class);
    }

    public function verifiedPayment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'verified_payment_id');
    }

    public function pendingInstructionChange(): HasOne
    {
        return $this->hasOne(DealInstructionChange::class)->where('status', 'pending');
    }
}
