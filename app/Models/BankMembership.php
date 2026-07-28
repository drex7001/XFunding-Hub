<?php

namespace App\Models;

use Database\Factories\BankMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_id', 'user_id', 'roles', 'active'])]
class BankMembership extends Model
{
    /** @use HasFactory<BankMembershipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['roles' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
