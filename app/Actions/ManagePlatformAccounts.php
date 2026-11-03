<?php

namespace App\Actions;

use App\Models\BankInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagePlatformAccounts
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * A null actor means the account was provisioned from the server console.
     * The account can only sign in after setting a password through the emailed link.
     */
    public function create(?User $actor, string $name, string $email, string $reason): User
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('managePlatformAccounts', User::class);
        }
        $email = Str::lower(trim($email));
        $account = DB::transaction(function () use ($actor, $name, $email, $reason): User {
            $hasActiveInvitation = BankInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists();
            if ($hasActiveInvitation || User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email already has an account or an active invitation.',
                ]);
            }
            $account = new User(['name' => $name, 'email' => $email, 'password' => Str::password(40)]);
            $account->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
            $this->audit->handle($actor, 'platform.account_created', $account, ['reason' => $reason]);

            return $account;
        });
        Password::sendResetLink(['email' => $account->email]);

        return $account;
    }

    public function deactivate(User $actor, User $account, string $reason): void
    {
        $this->changeStatus($actor, $account, false, 'platform.account_deactivated', $reason);
    }

    public function reactivate(User $actor, User $account, string $reason): void
    {
        $this->changeStatus($actor, $account, true, 'platform.account_reactivated', $reason);
    }

    private function changeStatus(User $actor, User $account, bool $active, string $action, string $reason): void
    {
        DB::transaction(function () use ($actor, $account, $active, $action, $reason): void {
            $locked = User::query()
                ->whereKey([$actor->id, $account->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $current = $locked->get($account->id);
            $authVersion = $actor->auth_version;
            $actor->refresh();
            abort_unless($actor->auth_version === $authVersion, 401, 'Please sign in again.');
            Gate::forUser($actor)->authorize('changePlatformAccountStatus', $current);
            abort_unless(
                $current->active !== $active,
                409,
                $active ? 'This account is already active.' : 'This account is already inactive.',
            );
            $current->forceFill(['active' => $active, 'auth_version' => $current->auth_version + 1])->save();
            $this->audit->handle($actor, $action, $current, ['reason' => $reason]);
        }, attempts: 3);
    }
}
