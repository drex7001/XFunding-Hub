<?php

namespace App\Models;

use App\ApprovalStatus;
use Database\Factories\SettlementConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deal_id',
    'bank_id',
    'proposed_by',
    'settlement_instruction_id',
    'settlement_at',
    'reference',
    'note',
    'pending_slot',
])]
#[Hidden([
    'bank_id',
    'proposed_by',
    'reviewed_by',
    'settlement_instruction_id',
    'reference',
    'note',
    'review_reason',
    'pending_slot',
    'approved_slot',
])]
class SettlementConfirmation extends Model
{
    /** @use HasFactory<SettlementConfirmationFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'settlement_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'reference' => 'encrypted',
            'note' => 'encrypted',
            'review_reason' => 'encrypted',
        ];
    }

    /**
     * A pending or approved attestation stands; rejected and superseded ones do not.
     */
    public function isCurrent(): bool
    {
        return in_array($this->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }
}
