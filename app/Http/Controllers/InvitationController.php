<?php

namespace App\Http\Controllers;

use App\Actions\RecordAuditEvent;
use App\BankStatus;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\BankMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    private function invitation(string $token): BankInvitation
    {
        abort_unless(strlen($token) === 64, 404);

        return BankInvitation::query()->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->whereHas('bank', fn (Builder $query): Builder => $query->where('status', '!=', BankStatus::Suspended->value))->firstOrFail();
    }

    public function show(string $token): View
    {
        return view('auth.invitation', ['invitation' => $this->invitation($token), 'token' => $token]);
    }

    public function store(AcceptInvitationRequest $request, string $token, RecordAuditEvent $audit): RedirectResponse
    {
        $invitation = $this->invitation($token);
        DB::transaction(function () use ($request, $invitation, $token, $audit): void {
            Bank::query()->lockForUpdate()->findOrFail($invitation->bank_id);
            $invitation = $this->invitation($token);
            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email already belongs to an account.']);
            }
            $user = User::create(['name' => $invitation->name, 'email' => $invitation->email, 'password' => $request->validated('password')]);
            $user->forceFill(['email_verified_at' => now()])->save();
            BankMembership::create(['bank_id' => $invitation->bank_id, 'user_id' => $user->id, 'roles' => $invitation->roles]);
            $invitation->forceFill(['accepted_at' => now()])->save();
            $audit->handle($user, 'invitation.accepted', $invitation);
        });

        return redirect()->route('login')->with('status', 'Invitation accepted. Sign in to your bank workspace.');
    }
}
