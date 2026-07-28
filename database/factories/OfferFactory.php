<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\FundingRequest;
use App\Models\Offer;
use App\Models\SettlementInstruction;
use App\Models\User;
use App\OfferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bank_id' => Bank::factory()->verified(), 'funding_request_id' => FundingRequest::factory()->published(),
            'created_by' => User::factory(),
            'settlement_instruction_id' => fn (array $attributes): string => SettlementInstruction::factory()->approved()->create(['bank_id' => $attributes['bank_id']])->id,
            'rate_units' => 81250, 'collateral' => 'unsecured', 'interest_payment' => 'at_maturity',
            'valid_until' => fn (array $attributes): CarbonImmutable => FundingRequest::query()->findOrFail($attributes['funding_request_id'])->settlement_date->endOfDay(),
            'status' => OfferStatus::Draft, 'version' => 1,
            'contributor_ids' => fn (array $attributes): array => [(int) $attributes['created_by']],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Active, 'active_slot' => fn (array $attributes): int => (int) $attributes['bank_id'],
            'label_number' => fn (array $attributes): int => ((int) Offer::query()->where('funding_request_id', $attributes['funding_request_id'])->max('label_number')) + 1,
            'activated_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => OfferStatus::PendingApproval]);
    }
}
