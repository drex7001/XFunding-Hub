<?php

namespace App\Policies;

use App\BankRole;
use App\Models\FundingRequest;
use App\Models\SelectionProposal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SelectionProposalPolicy
{
    public function viewAny(User $user, FundingRequest $request): Response
    {
        return $user->hasVerifiedBankAccess()
            && $user->membership->bank_id === $request->bank_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, FundingRequest $request): bool
    {
        return $this->viewAny($user, $request)->allowed() && $user->membership->bank->can_borrow && $user->hasBankRole(BankRole::Maker);
    }

    public function review(User $user, SelectionProposal $proposal): bool
    {
        return $this->viewAny($user, $proposal->fundingRequest)->allowed()
            && $user->membership->bank->can_borrow && $user->hasBankRole(BankRole::Approver) && $proposal->proposed_by !== $user->id;
    }
}
