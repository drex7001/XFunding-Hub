<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DealEvent> */
class DealEventFactory extends Factory
{
    protected $model = DealEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['deal_id' => Deal::factory(), 'kind' => 'confirmation.proposed', 'side' => 'borrower'];
    }
}
