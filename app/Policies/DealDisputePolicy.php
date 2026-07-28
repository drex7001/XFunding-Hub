<?php

namespace App\Policies;

use App\BankRole;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class DealDisputePolicy
{
    public function create(User $user, Deal $deal): bool
    {
        return Gate::forUser($user)->allows('view', $deal) && $user->hasBankRole(BankRole::Approver);
    }

    public function viewAny(User $user): bool
    {
        return $user->active && $user->is_platform_admin;
    }

    public function view(User $user, DealDispute $dispute): Response
    {
        return $this->viewAny($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function resolve(User $user, DealDispute $dispute): bool
    {
        return $this->viewAny($user);
    }
}
