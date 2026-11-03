<?php

namespace App\Actions;

use App\ApprovalStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SelectionProposal;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use App\SettlementInstructionStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SelectOffer
{
    public function __construct(
        private ManageOffers $offers,
        private RecordAuditEvent $audit,
        private QuotePlatformFee $fees,
        private RecordDealActivity $activity,
    ) {}

    public function propose(
        User $actor,
        FundingRequest $request,
        string $token,
        string $instructionId,
    ): SelectionProposal {
        Gate::forUser($actor)->authorize('viewAny', [SelectionProposal::class, $request]);
        Gate::forUser($actor)->authorize('create', [SelectionProposal::class, $request]);
        $offer = $request->offers()->where('borrower_token', $token)->firstOrFail();

        return $this->offers->withRequestLock(
            $actor,
            $request,
            $offer->bank_id,
            function (FundingRequest $current) use ($actor, $offer, $instructionId): SelectionProposal {
                Gate::forUser($actor)->authorize('create', [SelectionProposal::class, $current]);
                $this->offers->ensureOpen($current);
                abort_unless(
                    $current->pendingApproval === null
                        && $current->pendingSelection === null
                        && $current->deal === null,
                    409,
                    'Resolve the current request decision before proposing a selection.',
                );
                $candidate = $this->offers->lockedOffer($offer, $current);
                abort_unless($candidate->isSelectable(), 409, 'This offer is no longer available for selection.');
                $this->ensureFeeWindow($candidate);
                $instruction = $this->receivingInstruction($current, $instructionId);
                if ($instruction === null) {
                    throw ValidationException::withMessages([
                        'settlement_instruction_id' => 'Choose a current independently approved receiving instruction version for your institution.',
                    ]);
                }
                $proposal = SelectionProposal::create([
                    'funding_request_id' => $current->id, 'offer_id' => $candidate->id, 'proposed_by' => $actor->id,
                    'expected_offer_version' => $candidate->version,
                    'borrower_settlement_instruction_id' => $instruction->id,
                    'pending_slot' => $current->id,
                ]);
                $this->audit->handle($actor, 'selection.proposed', $current, ['label' => $candidate->label_number]);

                return $proposal;
            },
        );
    }

    public function review(User $actor, SelectionProposal $proposal, bool $approve, ?string $reason): ?Deal
    {
        Gate::forUser($actor)->authorize('viewAny', [SelectionProposal::class, $proposal->fundingRequest]);

        return $this->offers->withRequestLock(
            $actor,
            $proposal->fundingRequest,
            $proposal->offer->bank_id,
            function (FundingRequest $request) use ($actor, $proposal, $approve, $reason): ?Deal {
                $current = SelectionProposal::query()
                    ->where('funding_request_id', $request->id)
                    ->lockForUpdate()
                    ->findOrFail($proposal->id);
                $current->setRelation('fundingRequest', $request);
                Gate::forUser($actor)->authorize('review', $current);
                abort_unless(
                    $current->status === ApprovalStatus::Pending && $request->pendingSelection?->id === $current->id,
                    409,
                    'This selection is no longer pending.',
                );
                if (! $approve) {
                    $current->forceFill([
                        'status' => ApprovalStatus::Rejected,
                        'pending_slot' => null,
                        'reviewed_by' => $actor->id,
                        'reviewed_at' => now(),
                        'reason' => $reason,
                    ])->save();
                    $this->audit->handle($actor, 'selection.rejected', $request);

                    return null;
                }
                $this->offers->ensureOpen($request);
                abort_unless(
                    $request->pendingApproval === null && $request->deal === null,
                    409,
                    'The request already has a decision or deal.',
                );
                $offer = $this->offers->lockedOffer($proposal->offer, $request);
                abort_unless(
                    $offer->isSelectable() && $offer->version === $current->expected_offer_version,
                    409,
                    'This offer is no longer available at the proposed terms.',
                );
                $this->offers->ensureOfferValid($offer);
                $this->ensureFeeWindow($offer);
                $instruction = $this->receivingInstruction($request, $current->borrower_settlement_instruction_id);
                abort_unless(
                    $instruction !== null,
                    409,
                    'The proposed receiving instructions are no longer current. Reject this proposal and propose the selection again.',
                );
                $terms = [
                    'label' => 'Offer '.$offer->label_number, 'rate' => $offer->rate,
                    'amount' => $request->amount, 'currency' => $request->currency, 'tenorDays' => $request->tenor_days,
                    'settlementDate' => $request->settlement_date->toDateString(), 'dayCount' => $request->day_count,
                    'rateBasis' => $request->rate_basis,
                    'collateral' => $offer->collateral,
                    'interestPayment' => $offer->interest_payment,
                    'borrower' => $request->borrower_profile,
                ];
                $deal = Deal::create([
                    'funding_request_id' => $request->id, 'offer_id' => $offer->id,
                    'borrower_bank_id' => $request->bank_id, 'lender_bank_id' => $offer->bank_id,
                    'settlement_instruction_id' => $offer->settlement_instruction_id, 'terms' => $terms,
                    'instruction_snapshot' => $offer->settlementInstruction->snapshot(),
                    'borrower_settlement_instruction_id' => $instruction->id,
                    'borrower_instruction_snapshot' => $instruction->snapshot(),
                    'offer_valid_until' => $offer->valid_until,
                ]);
                $this->fees->apply($deal->refresh());
                $current->forceFill([
                    'status' => ApprovalStatus::Approved,
                    'pending_slot' => null,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'reason' => $reason,
                ])->save();
                $offer->forceFill(['status' => OfferStatus::Selected])->save();
                $request->forceFill(['status' => FundingRequestStatus::Selected])->save();
                $this->offers->closeForRequest($request, OfferStatus::Unsuccessful, $offer->id);
                $this->audit->handle($actor, 'selection.approved', $request, ['label' => $offer->label_number]);
                $this->activity->notify($actor, $deal, 'selected');

                return $deal->refresh();
            },
        );
    }

    private function ensureFeeWindow(Offer $offer): void
    {
        abort_unless(
            $offer->leavesFeeWindow(),
            409,
            'This offer expires too soon to complete the platform fee payment. Choose an offer with more remaining validity.',
        );
    }

    private function receivingInstruction(FundingRequest $request, ?string $id): ?SettlementInstruction
    {
        $instruction = SettlementInstruction::query()
            ->where('bank_id', $request->bank_id)
            ->where('status', SettlementInstructionStatus::Approved)
            ->with('bank')
            ->find($id);

        return $instruction?->matchesBankProfile() ? $instruction : null;
    }
}
