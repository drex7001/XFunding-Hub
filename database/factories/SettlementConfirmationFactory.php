<?php

namespace Database\Factories;

use App\BankRole;
use App\Models\BankMembership;
use App\Models\Deal;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SettlementConfirmation> */
class SettlementConfirmationFactory extends Factory
{
    protected $model = SettlementConfirmation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['deal_id' => Deal::factory(),
            'bank_id' => fn (array $a): int => Deal::query()->findOrFail($a['deal_id'])->borrower_bank_id,
            'proposed_by' => function (array $a): int {
                $user = User::factory()->create();
                BankMembership::factory()->for($user)->create(['bank_id' => $a['bank_id'], 'roles' => [BankRole::Maker->value]]);

                return $user->id;
            },
            'settlement_instruction_id' => fn (array $a): string => Deal::query()->findOrFail($a['deal_id'])->settlement_instruction_id,
            'settlement_at' => now(), 'reference' => 'SYNTHETIC-TRANSFER-'.fake()->unique()->numerify('######'),
            'note' => 'Synthetic institution attestation.', 'status' => 'pending',
            'pending_slot' => fn (array $a): string => $a['deal_id'].':'.$a['bank_id']];
    }
}
