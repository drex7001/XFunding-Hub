<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SettlementInstruction> */
class SettlementInstructionFactory extends Factory
{
    protected $model = SettlementInstruction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bank_id' => Bank::factory()->verified(), 'created_by' => User::factory(),
            'version' => fn (array $attributes): int => ((int) SettlementInstruction::query()->where('bank_id', $attributes['bank_id'])->max('version')) + 1,
            'details' => ['channel' => 'swift', 'account_name' => 'Synthetic Treasury Account', 'account_number' => 'DEMO000012345678', 'bic' => 'DEMOLKXX', 'contact_name' => 'Demo Treasury Desk', 'contact_email' => 'treasury@synthetic.example'],
            'bank_profile' => fn (array $attributes): array => SettlementInstruction::profileOf(Bank::query()->findOrFail($attributes['bank_id'])),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => 'approved', 'reviewed_by' => User::factory(), 'reviewed_at' => now()]);
    }
}
