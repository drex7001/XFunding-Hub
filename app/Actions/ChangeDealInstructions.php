<?php

namespace App\Actions;

use App\DealStatus;
use App\FeePaymentStatus;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\PaymentReceiptStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeDealInstructions
{
    public function __construct(private WithLockedDeal $lock, private RecordAuditEvent $audit, private RecordDealActivity $activity) {}

    public function propose(User $actor, Deal $deal, string $instructionId): DealInstructionChange
    {
        Gate::forUser($actor)->authorize('view', $deal);
        Gate::forUser($actor)->authorize('create', [DealInstructionChange::class, $deal]);

        return $this->lock->handle($actor, $deal, function (Deal $current) use ($actor, $instructionId): DealInstructionChange {
            Gate::forUser($actor)->authorize('create', [DealInstructionChange::class, $current]);
            $this->ensureChangeable($current);
            abort_unless($current->pendingInstructionChange === null, 409, 'Resolve the pending instruction change first.');
            abort_unless($instructionId !== $current->settlement_instruction_id, 409, 'Choose a different approved instruction version.');
            $instruction = $this->approvedInstruction($current, $instructionId);
            $change = DealInstructionChange::create(['deal_id' => $current->id, 'settlement_instruction_id' => $instruction->id, 'proposed_by' => $actor->id, 'pending_slot' => $current->id]);
            $this->audit->handle($actor, 'deal.instruction_change_proposed', $instruction, ['deal_id' => $current->id]);

            return $change->refresh();
        });
    }

    public function review(User $actor, DealInstructionChange $change, bool $approve, ?string $reason): Deal
    {
        Gate::forUser($actor)->authorize('view', $change->deal);

        return $this->lock->handle($actor, $change->deal, function (Deal $deal) use ($actor, $change, $approve, $reason): Deal {
            $current = DealInstructionChange::query()->where('deal_id', $deal->id)->lockForUpdate()->findOrFail($change->id);
            $current->setRelation('deal', $deal);
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($current->status === 'pending', 409, 'This instruction change is no longer pending.');
            if ($approve) {
                $this->ensureChangeable($deal);
                $instruction = $this->approvedInstruction($deal, $current->settlement_instruction_id);
                $current->forceFill(['previous_snapshot' => $deal->instruction_snapshot]);
                $deal->forceFill(['settlement_instruction_id' => $instruction->id, 'instruction_snapshot' => ['bank' => $instruction->bank_profile, 'details' => $instruction->details, 'version' => $instruction->version], 'instructions_updated_at' => now()])->save();
                if ($deal->status === DealStatus::Disputed) {
                    $deal->confirmations()->whereIn('status', ['pending', 'approved'])->update(['status' => 'superseded', 'pending_slot' => null, 'approved_slot' => null, 'updated_at' => now()]);
                    $this->activity->handle($actor, $deal, 'confirmations.superseded');
                }
                $this->audit->handle(null, 'deal.instructions_updated', $deal->fundingRequest, ['deal_id' => $deal->id]);
            }
            $current->forceFill(['status' => $approve ? 'approved' : 'rejected', 'pending_slot' => null, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason])->save();
            $this->audit->handle($actor, $approve ? 'deal.instruction_change_approved' : 'deal.instruction_change_rejected', $current->settlementInstruction, ['deal_id' => $deal->id]);

            return $deal->refresh();
        });
    }

    private function ensureChangeable(Deal $deal): void
    {
        abort_unless(($deal->currentStatus() === DealStatus::AwaitingFee || ($deal->status === DealStatus::Disputed && $deal->activeDispute !== null && $deal->hasVerifiedFee())) && $deal->banksEligible(), 409, 'Instructions require a fee-pending reservation or an open dispute with verified payment, while both institutions remain eligible.');
        abort_if($deal->feePayments()->where('status', FeePaymentStatus::Pending)->exists() || $deal->receipts()->where('payment_receipts.status', PaymentReceiptStatus::RefundReview)->exists(), 409, 'Resolve pending payments or refunds before changing instructions.');
    }

    private function approvedInstruction(Deal $deal, string $id): SettlementInstruction
    {
        $instruction = SettlementInstruction::query()->where('bank_id', $deal->lender_bank_id)->where('status', 'approved')->with('bank')->find($id);
        if ($instruction === null || ! $instruction->matchesBankProfile()) {
            throw ValidationException::withMessages(['settlement_instruction_id' => 'Choose a current independently approved instruction version for your institution.']);
        }

        return $instruction;
    }
}
