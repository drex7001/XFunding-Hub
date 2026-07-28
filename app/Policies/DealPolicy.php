<?php

namespace App\Policies;

use App\BankRole;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedBankAccess();
    }

    public function view(User $user, Deal $deal): Response
    {
        return $user->hasVerifiedBankAccess() && in_array($user->membership->bank_id, [$deal->borrower_bank_id, $deal->lender_bank_id], true)
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function viewSettlement(User $user, Deal $deal): bool
    {
        return $this->view($user, $deal)->allowed() && ($user->hasBankRole(BankRole::Maker) || $user->hasBankRole(BankRole::Approver))
            && $deal->banksEligible() && $deal->hasApprovedInstructions() && $deal->hasVerifiedFee()
            && $deal->pendingInstructionChange === null;
    }
}
