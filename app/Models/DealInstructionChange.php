<?php

namespace App\Models;

use Database\Factories\DealInstructionChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deal_id', 'settlement_instruction_id', 'proposed_by', 'pending_slot'])]
#[Hidden(['settlement_instruction_id', 'proposed_by', 'reviewed_by', 'previous_snapshot'])]
class DealInstructionChange extends Model
{
    /** @use HasFactory<DealInstructionChangeFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['previous_snapshot' => 'encrypted:array', 'reviewed_at' => 'immutable_datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function settlementInstruction(): BelongsTo
    {
        return $this->belongsTo(SettlementInstruction::class);
    }
}
