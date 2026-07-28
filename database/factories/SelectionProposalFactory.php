<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\SelectionProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SelectionProposal> */
class SelectionProposalFactory extends Factory
{
    protected $model = SelectionProposal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->active(),
            'funding_request_id' => fn (array $attributes): string => Offer::query()->findOrFail($attributes['offer_id'])->funding_request_id,
            'proposed_by' => User::factory(), 'expected_offer_version' => 1,
            'pending_slot' => fn (array $attributes): string => $attributes['funding_request_id'], 'status' => 'pending',
        ];
    }
}
