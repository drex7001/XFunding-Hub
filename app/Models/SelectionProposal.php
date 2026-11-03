<?php

namespace App\Models;

use Database\Factories\SelectionProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['funding_request_id', 'offer_id', 'proposed_by', 'expected_offer_version', 'borrower_settlement_instruction_id', 'pending_slot'])]
#[Hidden(['offer_id', 'borrower_settlement_instruction_id'])]
class SelectionProposal extends Model
{
    /** @use HasFactory<SelectionProposalFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['expected_offer_version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function borrowerInstruction(): BelongsTo
    {
        return $this->belongsTo(SettlementInstruction::class, 'borrower_settlement_instruction_id');
    }

    public function fundingRequest(): BelongsTo
    {
        return $this->belongsTo(FundingRequest::class);
    }
}
