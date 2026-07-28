<?php

namespace App\Models;

use App\BankRole;
use App\BankStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'auth_version'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'active' => 'boolean',
            'auth_version' => 'integer',
        ];
    }

    public function membership(): HasOne
    {
        return $this->hasOne(BankMembership::class);
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }

    public function hasBankRole(BankRole $role, ?Bank $bank = null): bool
    {
        return $this->active
            && ! $this->is_platform_admin
            && $this->membership?->active
            && ($bank === null || $this->membership->bank_id === $bank->id)
            && in_array($role->value, $this->membership->roles, true);
    }

    public function hasVerifiedBankAccess(): bool
    {
        return $this->active && ! $this->is_platform_admin
            && $this->membership?->active
            && $this->membership->roles !== []
            && $this->membership->bank->status === BankStatus::Verified;
    }

    public function canBrowseMarketplace(): bool
    {
        return $this->hasVerifiedBankAccess() && $this->membership->bank->can_lend
            && ($this->hasBankRole(BankRole::Maker) || $this->hasBankRole(BankRole::Approver));
    }
}
