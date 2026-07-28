<?php

namespace App\Models;

use Database\Factories\DealDisputeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deal_id', 'bank_id', 'opened_by', 'description', 'open_slot'])]
#[Hidden(['bank_id', 'opened_by', 'resolved_by', 'description', 'resolution_note', 'open_slot'])]
class DealDispute extends Model
{
    /** @use HasFactory<DealDisputeFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['description' => 'encrypted', 'resolution_note' => 'encrypted', 'resolved_at' => 'immutable_datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
