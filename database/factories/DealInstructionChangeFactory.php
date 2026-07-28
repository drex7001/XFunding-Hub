<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealInstructionChange;
use App\Models\SettlementInstruction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DealInstructionChange> */
class DealInstructionChangeFactory extends Factory
{
    protected $model = DealInstructionChange::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory()->quoted(), 'proposed_by' => User::factory(),
            'settlement_instruction_id' => fn (array $a): string => SettlementInstruction::factory()->approved()->create(['bank_id' => Deal::query()->findOrFail($a['deal_id'])->lender_bank_id])->id,
            'pending_slot' => fn (array $a): string => $a['deal_id'], 'status' => 'pending',
        ];
    }
}
