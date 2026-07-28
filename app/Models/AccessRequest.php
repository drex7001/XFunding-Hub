<?php

namespace App\Models;

use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_id', 'requested_by', 'membership_id', 'membership_version', 'kind', 'name', 'email', 'roles'])]
class AccessRequest extends Model
{
    /** @use HasFactory<AccessRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['roles' => 'array', 'reviewed_at' => 'datetime', 'membership_version' => 'integer'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(BankMembership::class);
    }
}
