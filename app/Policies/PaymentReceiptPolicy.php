<?php

namespace App\Policies;

use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PaymentReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active && $user->is_platform_admin;
    }

    public function view(User $user, PaymentReceipt $receipt): Response
    {
        return $this->viewAny($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function refund(User $user, PaymentReceipt $receipt): bool
    {
        return $this->viewAny($user);
    }
}
