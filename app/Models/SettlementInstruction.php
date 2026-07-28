<?php

namespace App\Models;

use Database\Factories\SettlementInstructionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_id', 'created_by', 'version', 'details', 'bank_profile'])]
#[Hidden(['details', 'bank_profile'])]
class SettlementInstruction extends Model
{
    /** @use HasFactory<SettlementInstructionFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'bank_profile' => 'array', 'version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function matchesBankProfile(): bool
    {
        $expected = $this->bank_profile;
        $current = self::profileOf($this->bank);
        ksort($expected);
        ksort($current);

        return $expected === $current;
    }

    /** @return array<string, string> */
    public static function profileOf(Bank $bank): array
    {
        return ['name' => $bank->name, 'country_code' => $bank->country_code, 'registration_number' => $bank->registration_number];
    }
}
