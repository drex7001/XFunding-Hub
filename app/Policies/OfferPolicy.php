<?php

namespace App\Policies;

use App\BankRole;
use App\FundingRequestStatus;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\User;
use App\OfferStatus;
use Illuminate\Auth\Access\Response;

class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedBankAccess() && $user->membership->bank->can_lend;
    }

    public function view(User $user, Offer $offer): Response
    {
        return $this->viewAny($user) && $user->membership->bank_id === $offer->bank_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, FundingRequest $request): bool
    {
        return $user->canBrowseMarketplace() && $user->hasBankRole(BankRole::Maker)
            && $user->membership->bank_id !== $request->bank_id && $user->can('view', $request)
            && $request->currentStatus() === FundingRequestStatus::Open;
    }

    public function update(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer)->allowed() && $this->create($user, $offer->fundingRequest)
            && in_array($offer->currentStatus(), [OfferStatus::Draft, OfferStatus::PendingApproval], true);
    }

    public function submit(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer)->allowed() && $user->hasBankRole(BankRole::Maker);
    }

    public function withdraw(User $user, Offer $offer): bool
    {
        return $this->submit($user, $offer);
    }

    public function review(User $user, Offer $offer): bool
    {
        return $this->view($user, $offer)->allowed() && $user->hasBankRole(BankRole::Approver)
            && $offer->pendingApproval !== null && $offer->pendingApproval->proposed_by !== $user->id
            && ! in_array($user->id, $offer->contributor_ids, true);
    }
}
