<?php

namespace App\Policies;

use App\BankRole;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SettlementInstructionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canBrowseMarketplace();
    }

    public function create(User $user): bool
    {
        return $user->canBrowseMarketplace() && $user->hasBankRole(BankRole::Maker);
    }

    public function view(User $user, SettlementInstruction $instruction): Response
    {
        return $this->viewAny($user) && $user->membership->bank_id === $instruction->bank_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function review(User $user, SettlementInstruction $instruction): bool
    {
        return $this->view($user, $instruction)->allowed() && $user->hasBankRole(BankRole::Approver) && $instruction->created_by !== $user->id;
    }
}
