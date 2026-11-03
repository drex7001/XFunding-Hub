<?php

namespace App\Policies;

use App\BankRole;
use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\User;

class DealInstructionChangePolicy
{
    public function create(User $user, Deal $deal): bool
    {
        return $this->holdsSideCapability($user, $deal->sideOf($user->membership?->bank_id))
            && $user->hasBankRole(BankRole::Maker);
    }

    public function review(User $user, DealInstructionChange $change): bool
    {
        return $change->deal->sideOf($user->membership?->bank_id) === $change->side
            && $this->holdsSideCapability($user, $change->side)
            && $user->hasBankRole(BankRole::Approver) && $change->proposed_by !== $user->id;
    }

    /**
     * The side always comes from the user's own membership, never from the request.
     */
    private function holdsSideCapability(User $user, ?string $side): bool
    {
        if ($side === null || ! $user->canManageSettlementInstructions()) {
            return false;
        }

        return $side === 'borrower' ? $user->membership->bank->can_borrow : $user->membership->bank->can_lend;
    }
}
