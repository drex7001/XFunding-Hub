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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['funding_request_id', 'offer_id', 'borrower_bank_id', 'lender_bank_id', 'settlement_instruction_id', 'terms', 'instruction_snapshot', 'borrower_settlement_instruction_id', 'borrower_instruction_snapshot', 'offer_valid_until'])]
#[Hidden(['offer_id', 'borrower_bank_id', 'lender_bank_id', 'settlement_instruction_id', 'instruction_snapshot', 'borrower_settlement_instruction_id', 'borrower_instruction_snapshot', 'verified_payment_id'])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class, 'terms' => 'array', 'instruction_snapshot' => 'encrypted:array', 'borrower_instruction_snapshot' => 'encrypted:array',
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

    /**
     * Loads everything the workflow checks read, so lists stay free of per-deal queries.
     */
    public function scopeWithWorkflowContext(Builder $query): Builder
    {
        return $query->with(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'borrowerInstruction.bank', 'verifiedPayment.verifiedReceipt', 'pendingInstructionChange.settlementInstruction', 'pendingInstructionChange.deal', 'fundingRequest', 'activeDispute', 'confirmations'])
            ->withCount([
                'feePayments as pending_payments_count' => fn (Builder $payments): Builder => $payments->where('status', FeePaymentStatus::Pending),
                'receipts as pending_refunds_count' => fn (Builder $receipts): Builder => $receipts->where('payment_receipts.status', PaymentReceiptStatus::RefundReview),
            ]);
    }

    public function isReadyForPayment(): bool
    {
        return app(DemoPaymentProvider::class)->enabled()
            && $this->currentStatus() === DealStatus::AwaitingFee && $this->hasFeeQuote() && $this->banksEligible()
            && $this->hasApprovedInstructions() && $this->pendingInstructionChange === null
            && ! $this->hasPendingPayment() && ! $this->hasPendingRefund();
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
        return $this->hasApprovedLenderInstructions() && $this->hasApprovedBorrowerInstructions();
    }

    public function hasApprovedLenderInstructions(): bool
    {
        return $this->recordedInstructionMatches($this->settlementInstruction, $this->lender_bank_id, $this->instruction_snapshot);
    }

    public function hasApprovedBorrowerInstructions(): bool
    {
        return $this->recordedInstructionMatches($this->borrowerInstruction, $this->borrower_bank_id, $this->borrower_instruction_snapshot);
    }

    /**
     * Deals selected before borrower instructions existed keep their lender disclosure.
     */
    public function recordedInstructionsRemainValid(): bool
    {
        return $this->hasApprovedLenderInstructions()
            && ($this->borrower_settlement_instruction_id === null || $this->hasApprovedBorrowerInstructions());
    }

    /**
     * Retiring a version is refused while an open deal uses it, so a retired version only remains on closed deals.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private function recordedInstructionMatches(?SettlementInstruction $instruction, int $bankId, ?array $snapshot): bool
    {
        return $instruction !== null && $instruction->bank_id === $bankId
            && in_array($instruction->status, ['approved', 'retired'], true)
            && $instruction->matchesBankProfile() && $snapshot !== null
            && ($snapshot['bank'] ?? null) == $instruction->bank_profile
            && ($snapshot['details'] ?? null) == $instruction->details
            && ($snapshot['version'] ?? null) === $instruction->version;
    }

    public function sideOf(?int $bankId): ?string
    {
        return match ($bankId) {
            $this->borrower_bank_id => 'borrower',
            $this->lender_bank_id => 'lender',
            default => null,
        };
    }

    public function currentInstructionIdFor(string $side): ?string
    {
        return $side === 'borrower' ? $this->borrower_settlement_instruction_id : $this->settlement_instruction_id;
    }

    /**
     * Instructions change before payment or during a dispute. A borrower may also add the
     * receiving version that a deal selected before this requirement never recorded.
     */
    public function acceptsInstructionChangeFrom(string $side): bool
    {
        if ($this->currentStatus() === DealStatus::AwaitingFee) {
            return true;
        }
        if ($this->status === DealStatus::Disputed) {
            return $this->activeDispute !== null && $this->hasVerifiedFee();
        }

        return $side === 'borrower' && $this->borrower_settlement_instruction_id === null
            && $this->status === DealStatus::Unlocked && $this->hasVerifiedFee();
    }

    /**
     * Only the borrower sees the comparison label; it reveals how many offers were activated.
     */
    public function labelFor(?int $bankId): string
    {
        return $bankId === $this->borrower_bank_id ? ($this->terms['label'] ?? 'Selected offer') : 'Your offer';
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
            && $payment->provider === 'demo' && $receipt->provider === 'demo' && app(DemoPaymentProvider::class)->honorsRecordedProof();
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

    public function borrowerInstruction(): BelongsTo
    {
        return $this->belongsTo(SettlementInstruction::class, 'borrower_settlement_instruction_id');
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
