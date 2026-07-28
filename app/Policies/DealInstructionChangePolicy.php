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
        return $user->canBrowseMarketplace() && $user->membership->bank_id === $deal->lender_bank_id && $user->hasBankRole(BankRole::Maker);
    }

    public function review(User $user, DealInstructionChange $change): bool
    {
        return $user->canBrowseMarketplace() && $user->membership->bank_id === $change->deal->lender_bank_id
            && $user->hasBankRole(BankRole::Approver) && $change->proposed_by !== $user->id;
    }
}
