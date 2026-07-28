<?php

namespace App\Actions;

use App\BankRole;
use App\DealStatus;
use App\FundingRequestStatus;
use App\Models\Deal;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class ManageSettlementConfirmations
{
    public function __construct(private WithLockedDeal $lock, private RecordDealActivity $activity) {}

    /** @param array{settlement_at: string, reference?: ?string, note?: ?string} $data */
    public function propose(User $actor, Deal $deal, array $data): SettlementConfirmation
    {
        Gate::forUser($actor)->authorize('view', $deal);
        Gate::forUser($actor)->authorize('create', [SettlementConfirmation::class, $deal]);

        return $this->lock->handle($actor, $deal, function (Deal $current) use ($actor, $data): SettlementConfirmation {
            Gate::forUser($actor)->authorize('create', [SettlementConfirmation::class, $current]);
            abort_unless($current->settlementReady(), 409, 'Settlement confirmation requires verified payment and current approved instructions from eligible institutions.');
            $bankId = $actor->membership->bank_id;
            abort_if($current->confirmations->contains(fn (SettlementConfirmation $record): bool => $record->bank_id === $bankId && in_array($record->status, ['pending', 'approved'], true)), 409, 'Your institution already has a pending or approved confirmation.');
            $time = CarbonImmutable::parse($data['settlement_at']);
            abort_unless($time->betweenIncluded($current->unlocked_at, now()), 422, 'Settlement time must be between fee verification and the current time.');
            $record = SettlementConfirmation::create([
                'deal_id' => $current->id, 'bank_id' => $bankId, 'proposed_by' => $actor->id,
                'settlement_instruction_id' => $current->settlement_instruction_id, 'settlement_at' => $time,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'pending_slot' => $current->id.':'.$bankId,
            ]);
            $this->activity->handle($actor, $current, 'confirmation.proposed', $bankId);

            return $record->setRelation('deal', $current);
        });
    }

    public function review(User $actor, SettlementConfirmation $confirmation, bool $approve, ?string $reason): Deal
    {
        Gate::forUser($actor)->authorize('view', $confirmation->deal);

        return $this->lock->handle($actor, $confirmation->deal, function (Deal $deal) use ($actor, $confirmation, $approve, $reason): Deal {
            $current = SettlementConfirmation::query()->where('deal_id', $deal->id)->lockForUpdate()->findOrFail($confirmation->id);
            $current->setRelation('deal', $deal);
            Gate::forUser($actor)->authorize('review', $current);
            abort_unless($current->status === 'pending' && in_array($deal->status, [DealStatus::Unlocked, DealStatus::Disputed], true), 409, 'This confirmation is no longer pending.');
            if ($approve) {
                abort_unless($deal->settlementReady() && $current->settlement_instruction_id === $deal->settlement_instruction_id, 409, 'The payment, institutions or instruction version no longer permit confirmation.');
                $maker = $current->proposer->fresh(['membership.bank']);
                abort_unless($maker->hasBankRole(BankRole::Maker) && $maker->membership->bank_id === $current->bank_id && $maker->hasVerifiedBankAccess(), 409, 'The proposing maker is no longer eligible.');
            }
            $current->forceFill([
                'status' => $approve ? 'approved' : 'rejected', 'pending_slot' => null,
                'approved_slot' => $approve ? $deal->id.':'.$current->bank_id : null,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason,
            ])->save();
            $this->activity->handle($actor, $deal, $approve ? 'confirmation.approved' : 'confirmation.rejected', $current->bank_id);
            $deal->load('confirmations');
            if ($approve && $deal->status === DealStatus::Unlocked && $deal->hasBothConfirmations()) {
                $this->settle($actor, $deal);
            }

            return $deal->refresh();
        });
    }

    public function settle(User $actor, Deal $deal): void
    {
        abort_unless($deal->settlementReady() && $deal->hasBothConfirmations(), 409, 'Both institutions must independently approve settlement against the current instruction version.');
        $deal->forceFill(['status' => DealStatus::Settled, 'settled_at' => now()])->save();
        $deal->fundingRequest->forceFill(['status' => FundingRequestStatus::Settled])->save();
        $this->activity->handle($actor, $deal, 'settled');
    }
}
