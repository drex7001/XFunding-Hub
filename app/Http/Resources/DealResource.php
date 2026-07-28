<?php

namespace App\Http\Resources;

use App\Actions\DemoPaymentProvider;
use App\DealStatus;
use App\Models\DealDispute;
use App\Models\FeePayment;
use App\Models\FundingRequest;
use App\Models\SettlementConfirmation;
use App\Models\SettlementInstruction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class DealResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payable = Gate::allows('create', [FeePayment::class, $this->resource]) && app(DemoPaymentProvider::class)->enabled()
            && $this->currentStatus() === DealStatus::AwaitingFee && $this->hasFeeQuote() && $this->banksEligible()
            && $this->hasApprovedInstructions() && $this->pendingInstructionChange === null
            && ! $this->hasPendingPayment() && ! $this->hasPendingRefund();
        $data = [
            'id' => $this->id, 'fundingRequestId' => $this->funding_request_id,
            'status' => $this->currentStatus()->value, 'terms' => $this->terms,
            'offerValidUntil' => $this->offer_valid_until->toIso8601String(),
            'feeConfigured' => $this->hasFeeQuote(), 'identityUnlocked' => $this->hasVerifiedFee(),
            'fee' => $this->hasFeeQuote() ? [
                'amount' => FundingRequest::formatMinor($this->fee_amount_minor), 'currency' => $this->fee_currency,
                'baseAmount' => FundingRequest::formatMinor($this->fee_policy['baseMinor']), 'taxAmount' => FundingRequest::formatMinor($this->fee_policy['taxMinor']),
                'basisPoints' => $this->fee_policy['basisPoints'], 'taxBasisPoints' => $this->fee_policy['taxBasisPoints'],
                'mode' => $this->fee_policy['mode'], 'policyVersion' => $this->fee_policy['version'], 'deadline' => $this->fee_deadline->toIso8601String(),
            ] : null,
            'settlement' => [
                'borrower' => $this->confirmations->first(fn (SettlementConfirmation $record): bool => $record->bank_id === $this->borrower_bank_id && $record->settlement_instruction_id === $this->settlement_instruction_id && in_array($record->status, ['pending', 'approved'], true))?->status ?? 'not_submitted',
                'lender' => $this->confirmations->first(fn (SettlementConfirmation $record): bool => $record->bank_id === $this->lender_bank_id && $record->settlement_instruction_id === $this->settlement_instruction_id && in_array($record->status, ['pending', 'approved'], true))?->status ?? 'not_submitted',
                'settledAt' => $this->settled_at?->toIso8601String(), 'closedUnsettledAt' => $this->closed_unsettled_at?->toIso8601String(),
                'disputeOpen' => $this->activeDispute !== null,
            ],
            'instructionsUpdatedAt' => $this->instructions_updated_at?->toIso8601String(),
            'instructionReviewPending' => $this->pendingInstructionChange !== null,
            'can' => ['initiatePayment' => $payable, 'viewSettlement' => Gate::allows('viewSettlement', $this->resource),
                'proposeConfirmation' => Gate::allows('create', [SettlementConfirmation::class, $this->resource]) && $this->settlementReady()
                    && ! $this->confirmations->contains(fn (SettlementConfirmation $record): bool => $record->bank_id === $request->user()->membership?->bank_id && in_array($record->status, ['pending', 'approved'], true)),
                'openDispute' => Gate::allows('create', [DealDispute::class, $this->resource]) && $this->status === DealStatus::Unlocked && $this->hasVerifiedFee(),
            ],
        ];
        if ($request->user()->membership?->bank_id === $this->lender_bank_id && Gate::allows('viewAny', SettlementInstruction::class)) {
            $change = $this->pendingInstructionChange;
            $data['instructionChange'] = $change ? ['id' => $change->id, 'version' => $change->settlementInstruction->version, 'canReview' => Gate::allows('review', $change)] : null;
        }

        return $data;
    }
}
