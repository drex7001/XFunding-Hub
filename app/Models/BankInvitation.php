<?php

namespace App\Models;

use Database\Factories\BankInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_id', 'invited_by', 'name', 'email', 'roles', 'token_hash', 'expires_at'])]
#[Hidden(['token_hash'])]
class BankInvitation extends Model
{
    /** @use HasFactory<BankInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['roles' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
