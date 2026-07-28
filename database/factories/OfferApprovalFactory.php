<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfferApproval> */
class OfferApprovalFactory extends Factory
{
    protected $model = OfferApproval::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory()->pending(),
            'proposed_by' => fn (array $attributes): int => Offer::query()->findOrFail($attributes['offer_id'])->created_by,
            'expected_version' => 1, 'status' => 'pending',
        ];
    }
}
