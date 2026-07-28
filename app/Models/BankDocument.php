<?php

namespace App\Models;

use Database\Factories\BankDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size'])]
#[Hidden(['path'])]
class BankDocument extends Model
{
    /** @use HasFactory<BankDocumentFactory> */
    use HasFactory;

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
