<?php

namespace App\Models;

use Database\Factories\FundingRequestApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['funding_request_id', 'proposed_by', 'expected_version', 'kind', 'proposal_reason', 'bank_profile'])]
class FundingRequestApproval extends Model
{
    /** @use HasFactory<FundingRequestApprovalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['bank_profile' => 'array', 'expected_version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    public function fundingRequest(): BelongsTo
    {
        return $this->belongsTo(FundingRequest::class);
    }
}
