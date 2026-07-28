<?php

namespace App\Models;

use Database\Factories\DealEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deal_id', 'kind', 'side'])]
class DealEvent extends Model
{
    /** @use HasFactory<DealEventFactory> */
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Deal events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Deal events are append-only.'));
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
