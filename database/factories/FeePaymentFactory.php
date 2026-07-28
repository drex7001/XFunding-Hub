<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\FeePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeePayment> */
class FeePaymentFactory extends Factory
{
    protected $model = FeePayment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory()->quoted(), 'initiated_by' => User::factory(),
            'bank_id' => fn (array $a): int => Deal::query()->findOrFail($a['deal_id'])->borrower_bank_id,
            'provider' => 'demo', 'provider_reference' => 'XFP-'.Str::uuid(),
            'amount_minor' => fn (array $a): int => Deal::query()->findOrFail($a['deal_id'])->fee_amount_minor,
            'currency' => fn (array $a): string => Deal::query()->findOrFail($a['deal_id'])->fee_currency,
            'pending_slot' => fn (array $a): string => $a['deal_id'], 'status' => 'pending',
        ];
    }
}
