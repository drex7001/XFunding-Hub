<?php

namespace Database\Factories;

use App\BankRole;
use App\Models\BankMembership;
use App\Models\Deal;
use App\Models\DealDispute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DealDispute> */
class DealDisputeFactory extends Factory
{
    protected $model = DealDispute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['deal_id' => Deal::factory(),
            'bank_id' => fn (array $a): int => Deal::query()->findOrFail($a['deal_id'])->borrower_bank_id,
            'opened_by' => function (array $a): int {
                $user = User::factory()->create();
                BankMembership::factory()->for($user)->create(['bank_id' => $a['bank_id'], 'roles' => [BankRole::Approver->value]]);

                return $user->id;
            },
            'description' => 'Synthetic settlement issue requiring operational review.', 'status' => 'open',
            'open_slot' => fn (array $a): string => $a['deal_id']];
    }
}
