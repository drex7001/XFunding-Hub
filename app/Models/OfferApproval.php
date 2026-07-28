<?php

namespace App\Models;

use Database\Factories\OfferApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'proposed_by', 'expected_version'])]
class OfferApproval extends Model
{
    /** @use HasFactory<OfferApprovalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['expected_version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
