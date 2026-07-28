<?php

namespace App\Actions;

use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\User;
use Closure;

class WithLockedDeal
{
    public function __construct(private ManageOffers $offers) {}

    /** @param Closure(Deal): mixed $callback */
    public function handle(?User $actor, Deal $deal, Closure $callback): mixed
    {
        return $this->offers->withRequestLock($actor, $deal->fundingRequest, $deal->lender_bank_id, function (FundingRequest $request) use ($deal, $callback): mixed {
            $current = Deal::query()->lockForUpdate()->findOrFail($deal->id);
            $current->setRelation('fundingRequest', $request)->load(['borrowerBank', 'lenderBank', 'settlementInstruction.bank', 'verifiedPayment.verifiedReceipt', 'pendingInstructionChange', 'activeDispute', 'confirmations']);

            return $callback($current);
        });
    }
}
