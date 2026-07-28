<?php

namespace App\Actions;

use App\BankRole;
use App\BankStatus;
use App\Models\AccessRequest;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\BankMembership;
use App\Models\User;
use App\Notifications\BankInvitationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageBankAccess
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $data */
    public function createBank(User $actor, array $data): Bank
    {
        Gate::forUser($actor)->authorize('create', Bank::class);

        return DB::transaction(function () use ($actor, $data): Bank {
            $bank = Bank::create(collect($data)->only(['name', 'country_code', 'registration_number', 'requested_capabilities'])->all());
            $this->issueInvitation($actor, $bank, $data['admin_name'], $data['admin_email'], [BankRole::Admin->value]);
            $this->audit->handle($actor, 'bank.created', $bank);

            return $bank;
        });
    }

    /** @param array<string, mixed> $data */
    public function propose(User $actor, Bank $bank, array $data, ?BankMembership $membership = null): AccessRequest
    {
        return DB::transaction(function () use ($actor, $bank, $data, $membership): AccessRequest {
            Bank::query()->lockForUpdate()->findOrFail($bank->id);
            $actor->refresh()->load('membership.bank');
            Gate::forUser($actor)->authorize('manageMembers', $bank);
            if ($membership !== null) {
                $membership->refresh();
                abort_unless($membership->bank_id === $bank->id, 404);
                abort_unless($membership->active, 409, 'This membership is inactive.');
            }
            $roles = $data['roles'];
            sort($roles);
            $request = AccessRequest::create([
                'bank_id' => $bank->id, 'requested_by' => $actor->id,
                'membership_id' => $membership?->id, 'membership_version' => $membership?->version,
                'kind' => $membership ? 'role_change' : 'invitation',
                'name' => $membership?->user->name ?? $data['name'],
                'email' => $membership?->user->email ?? $data['email'], 'roles' => $roles,
            ]);
            $this->audit->handle($actor, 'access.proposed', $request, ['roles' => $roles]);

            return $request;
        });
    }

    public function review(User $actor, AccessRequest $request, bool $approve): AccessRequest
    {
        return DB::transaction(function () use ($actor, $request, $approve): AccessRequest {
            Bank::query()->lockForUpdate()->findOrFail($request->bank_id);
            $request = AccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            $actor->refresh()->load('membership.bank');
            Gate::forUser($actor)->authorize('review', $request);
            abort_unless($request->status === 'pending', 409, 'This request has already been reviewed.');
            if ($approve) {
                abort_if($request->bank->status === BankStatus::Suspended, 409, 'This bank is suspended.');
                if ($request->kind === 'invitation') {
                    $this->issueInvitation($actor, $request->bank, $request->name, $request->email, $request->roles);
                } else {
                    $membership = $request->membership()->lockForUpdate()->firstOrFail();
                    abort_unless($membership->active && $membership->version === $request->membership_version, 409, 'Membership changed. Submit a new request.');
                    $this->protectLastAdmin($membership, $request->roles);
                    $membership->forceFill(['roles' => $request->roles, 'version' => $membership->version + 1])->save();
                    $membership->user()->increment('auth_version');
                }
            }
            $request->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            $this->audit->handle($actor, $approve ? 'access.approved' : 'access.rejected', $request, ['roles' => $request->roles]);

            return $request;
        });
    }

    public function revoke(User $actor, BankMembership $membership): void
    {
        DB::transaction(function () use ($actor, $membership): void {
            $bank = Bank::query()->lockForUpdate()->findOrFail($membership->bank_id);
            $actor->refresh()->load('membership.bank');
            Gate::forUser($actor)->authorize('manageMembers', $bank);
            $membership->refresh();
            abort_unless($membership->active, 409, 'This membership is already inactive.');
            $this->protectLastAdmin($membership, []);
            $membership->forceFill(['active' => false, 'version' => $membership->version + 1])->save();
            $membership->user()->increment('auth_version');
            $this->audit->handle($actor, 'membership.revoked', $membership);
        });
    }

    /** @param array<int, string> $roles */
    private function protectLastAdmin(BankMembership $membership, array $roles): void
    {
        if (in_array(BankRole::Admin->value, $membership->roles, true) && ! in_array(BankRole::Admin->value, $roles, true)) {
            $others = $membership->bank->memberships()->where('active', true)->whereKeyNot($membership->id)->whereHas('user', fn (Builder $query): Builder => $query->where('active', true))->get();
            abort_unless($others->contains(fn (BankMembership $other): bool => in_array(BankRole::Admin->value, $other->roles, true)), 409, 'A bank must retain an active bank admin.');
        }
    }

    /** @param array<int, string> $roles */
    private function issueInvitation(User $actor, Bank $bank, string $name, string $email, array $roles): void
    {
        $existing = BankInvitation::query()->where('email', $email)->lockForUpdate()->first();
        if (User::query()->where('email', $email)->exists() || ($existing && $existing->expires_at->isFuture() && $existing->accepted_at === null)) {
            throw ValidationException::withMessages(['email' => 'This email already has an account or an active invitation.']);
        }
        $existing?->delete();
        $token = Str::random(64);
        $invitation = BankInvitation::create([
            'bank_id' => $bank->id, 'invited_by' => $actor->id, 'name' => $name, 'email' => $email,
            'roles' => $roles, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(3),
        ]);
        Notification::route('mail', $email)->notify((new BankInvitationNotification($invitation, $token))->afterCommit());
        $this->audit->handle($actor, 'invitation.created', $invitation, ['roles' => $roles]);
    }
}
