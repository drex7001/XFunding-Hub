<?php

namespace App\Policies;

use App\BankRole;
use App\Models\Deal;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SettlementConfirmationPolicy
{
    public function create(User $user, Deal $deal): bool
    {
        return Gate::forUser($user)->allows('view', $deal) && $user->hasBankRole(BankRole::Maker);
    }

    public function review(User $user, SettlementConfirmation $confirmation): bool
    {
        return Gate::forUser($user)->allows('view', $confirmation->deal)
            && $user->membership->bank_id === $confirmation->bank_id
            && $user->hasBankRole(BankRole::Approver) && $confirmation->proposed_by !== $user->id;
    }

    public function viewDetails(User $user, SettlementConfirmation $confirmation): bool
    {
        return Gate::forUser($user)->allows('view', $confirmation->deal)
            && $user->membership->bank_id === $confirmation->bank_id
            && ($user->hasBankRole(BankRole::Maker) || $user->hasBankRole(BankRole::Approver));
    }
}
