<?php

namespace App\Actions;

use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\SelectionProposal;
use App\Models\User;
use App\OfferStatus;
use Illuminate\Support\Facades\Gate;

class SelectOffer
{
    public function __construct(private ManageOffers $offers, private RecordAuditEvent $audit, private QuotePlatformFee $fees) {}

    public function propose(User $actor, FundingRequest $request, string $token): SelectionProposal
    {
        Gate::forUser($actor)->authorize('viewAny', [SelectionProposal::class, $request]);
        Gate::forUser($actor)->authorize('create', [SelectionProposal::class, $request]);
        $offer = $request->offers()->where('borrower_token', $token)->firstOrFail();

        return $this->offers->withRequestLock($actor, $request, $offer->bank_id, function (FundingRequest $current) use ($actor, $offer): SelectionProposal {
            Gate::forUser($actor)->authorize('create', [SelectionProposal::class, $current]);
            $this->offers->ensureOpen($current);
            abort_unless($current->pendingApproval === null && $current->pendingSelection === null && $current->deal === null, 409, 'Resolve the current request decision before proposing a selection.');
            $candidate = $this->offers->lockedOffer($offer, $current);
            abort_unless($candidate->isSelectable(), 409, 'This offer is no longer available for selection.');
            $proposal = SelectionProposal::create(['funding_request_id' => $current->id, 'offer_id' => $candidate->id, 'proposed_by' => $actor->id, 'expected_offer_version' => $candidate->version, 'pending_slot' => $current->id]);
            $this->audit->handle($actor, 'selection.proposed', $current, ['label' => $candidate->label_number]);

            return $proposal;
        });
    }

    public function review(User $actor, SelectionProposal $proposal, bool $approve, ?string $reason): ?Deal
    {
        Gate::forUser($actor)->authorize('viewAny', [SelectionProposal::class, $proposal->fundingRequest]);

        return $this->offers->withRequestLock($actor, $proposal->fundingRequest, $proposal->offer->bank_id, function (FundingRequest $request) use ($actor, $proposal, $approve, $reason): ?Deal {
            $current = SelectionProposal::query()->where('funding_request_id', $request->id)->lockForUpdate()->findOrFail($proposal->id);
            $current->setRelation('fundingRequest', $request);
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($current->status === 'pending' && $request->pendingSelection?->id === $current->id, 409, 'This selection is no longer pending.');
            if (! $approve) {
                $current->forceFill(['status' => 'rejected', 'pending_slot' => null, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason])->save();
                $this->audit->handle($actor, 'selection.rejected', $request);

                return null;
            }
            $this->offers->ensureOpen($request);
            abort_unless($request->pendingApproval === null && $request->deal === null, 409, 'The request already has a decision or deal.');
            $offer = $this->offers->lockedOffer($proposal->offer, $request);
            abort_unless($offer->isSelectable() && $offer->version === $current->expected_offer_version, 409, 'This offer is no longer available at the proposed terms.');
            $this->offers->ensureOfferValid($offer);
            $terms = [
                'label' => 'Offer '.$offer->label_number, 'rate' => $offer->rate,
                'amount' => $request->amount, 'currency' => $request->currency, 'tenorDays' => $request->tenor_days,
                'settlementDate' => $request->settlement_date->toDateString(), 'dayCount' => $request->day_count,
                'rateBasis' => $request->rate_basis, 'collateral' => $offer->collateral, 'interestPayment' => $offer->interest_payment,
                'borrower' => $request->borrower_profile,
            ];
            $deal = Deal::create([
                'funding_request_id' => $request->id, 'offer_id' => $offer->id,
                'borrower_bank_id' => $request->bank_id, 'lender_bank_id' => $offer->bank_id,
                'settlement_instruction_id' => $offer->settlement_instruction_id, 'terms' => $terms,
                'instruction_snapshot' => ['bank' => $offer->settlementInstruction->bank_profile, 'details' => $offer->settlementInstruction->details, 'version' => $offer->settlementInstruction->version],
                'offer_valid_until' => $offer->valid_until,
            ]);
            $this->fees->apply($deal->refresh());
            $current->forceFill(['status' => 'approved', 'pending_slot' => null, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'reason' => $reason])->save();
            $offer->forceFill(['status' => OfferStatus::Selected])->save();
            $request->forceFill(['status' => FundingRequestStatus::Selected])->save();
            $this->offers->closeForRequest($request, OfferStatus::Unsuccessful, $offer->id);
            $this->audit->handle($actor, 'selection.approved', $request, ['label' => $offer->label_number]);

            return $deal->refresh();
        });
    }
}
