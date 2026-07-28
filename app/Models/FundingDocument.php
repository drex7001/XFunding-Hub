<?php

namespace App\Models;

use Database\Factories\FundingDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['funding_request_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size', 'sha256'])]
#[Hidden(['path'])]
class FundingDocument extends Model
{
    /** @use HasFactory<FundingDocumentFactory> */
    use HasFactory, HasUlids;

    public function fundingRequest(): BelongsTo
    {
        return $this->belongsTo(FundingRequest::class);
    }
}
