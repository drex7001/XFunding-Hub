<?php

namespace App\Actions;

use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ManageDealDisputes
{
    public function __construct(private WithLockedDeal $lock, private RecordDealActivity $activity, private ManageSettlementConfirmations $confirmations) {}

    public function open(User $actor, Deal $deal, string $description): DealDispute
    {
        Gate::forUser($actor)->authorize('view', $deal);
        Gate::forUser($actor)->authorize('create', [DealDispute::class, $deal]);

        return $this->lock->handle($actor, $deal, function (Deal $current) use ($actor, $description): DealDispute {
            Gate::forUser($actor)->authorize('create', [DealDispute::class, $current]);
            abort_unless($current->status === DealStatus::Unlocked && $current->hasVerifiedFee() && $current->activeDispute === null, 409, 'Only an unlocked deal can enter settlement dispute review.');
            $dispute = DealDispute::create(['deal_id' => $current->id, 'bank_id' => $actor->membership->bank_id, 'opened_by' => $actor->id, 'description' => $description, 'open_slot' => $current->id]);
            $current->forceFill(['status' => DealStatus::Disputed])->save();
            $this->activity->handle($actor, $current, 'dispute.opened', $dispute->bank_id);

            return $dispute->setRelation('deal', $current);
        });
    }

    public function resolve(User $actor, DealDispute $dispute, string $outcome, string $reason): Deal
    {
        Gate::forUser($actor)->authorize('resolve', $dispute);

        return $this->lock->handle($actor, $dispute->deal, function (Deal $deal) use ($actor, $dispute, $outcome, $reason): Deal {
            $current = DealDispute::query()->where('deal_id', $deal->id)->lockForUpdate()->findOrFail($dispute->id);
            Gate::forUser($actor)->authorize('resolve', $current);
            abort_unless($current->status === 'open' && $deal->status === DealStatus::Disputed && $deal->hasVerifiedFee(), 409, 'This dispute is no longer open.');
            abort_unless($deal->pendingInstructionChange === null, 409, 'Resolve the pending instruction review first.');
            if ($outcome === 'settled') {
                $this->confirmations->settle($actor, $deal);
            } else {
                abort_unless($outcome === 'closed_unsettled' && ! $deal->hasBothConfirmations(), 409, 'Both institutions have approved settlement; review the evidence before resolving.');
                $deal->forceFill(['status' => DealStatus::ClosedUnsettled, 'closed_unsettled_at' => now()])->save();
                $deal->fundingRequest->forceFill(['status' => FundingRequestStatus::Cancelled])->save();
                $deal->confirmations()->where('status', 'pending')->update(['status' => 'superseded', 'pending_slot' => null, 'updated_at' => now()]);
                $this->activity->handle($actor, $deal, 'closed_unsettled');
            }
            $current->forceFill(['status' => $outcome, 'open_slot' => null, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $reason])->save();
            $this->activity->handle($actor, $deal, 'dispute.resolved');

            return $deal->refresh();
        });
    }
}
