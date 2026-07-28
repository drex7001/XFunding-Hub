<?php

namespace App\Policies;

use App\BankRole;
use App\BankStatus;
use App\FundingRequestStatus;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FundingRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedBankAccess();
    }

    public function viewMarketplace(User $user): bool
    {
        return $user->canBrowseMarketplace();
    }

    public function view(User $user, FundingRequest $fundingRequest): Response
    {
        $owned = $user->hasVerifiedBankAccess() && $user->membership->bank_id === $fundingRequest->bank_id;
        $published = $user->canBrowseMarketplace()
            && ($fundingRequest->currentStatus() === FundingRequestStatus::Open
                || (in_array($fundingRequest->status, [FundingRequestStatus::Selected, FundingRequestStatus::Settled], true) && $fundingRequest->deal?->lender_bank_id === $user->membership->bank_id))
            && $fundingRequest->bank->status === BankStatus::Verified && $fundingRequest->bank->can_borrow;

        return $owned || $published ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedBankAccess() && $user->membership->bank->can_borrow && $user->hasBankRole(BankRole::Maker);
    }

    public function submit(User $user, FundingRequest $fundingRequest): bool
    {
        return $this->create($user) && $user->membership->bank_id === $fundingRequest->bank_id;
    }

    public function update(User $user, FundingRequest $fundingRequest): bool
    {
        return $this->submit($user, $fundingRequest) && $fundingRequest->isEditable();
    }

    public function proposeCancellation(User $user, FundingRequest $fundingRequest): bool
    {
        return $this->submit($user, $fundingRequest);
    }

    public function review(User $user, FundingRequest $fundingRequest): bool
    {
        $approval = $fundingRequest->pendingApproval;

        return $user->hasVerifiedBankAccess() && $user->membership->bank->can_borrow
            && $user->membership->bank_id === $fundingRequest->bank_id
            && $user->hasBankRole(BankRole::Approver) && $approval !== null
            && $approval->proposed_by !== $user->id
            && ($approval->kind !== 'publish' || ! in_array($user->id, $fundingRequest->contributor_ids, true));
    }
}
