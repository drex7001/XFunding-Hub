<?php

namespace App\Models;

use App\BankStatus;
use Database\Factories\BankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'country_code', 'registration_number', 'requested_capabilities'])]
class Bank extends Model
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => BankStatus::class, 'requested_capabilities' => 'array', 'can_borrow' => 'boolean', 'can_lend' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BankMembership::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BankDocument::class);
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class);
    }
}
