<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditEvent> */
class AuditEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['bank_id' => Bank::factory(), 'actor_id' => User::factory(), 'action' => 'bank.created', 'subject_type' => Bank::class, 'subject_id' => '1', 'context' => []];
    }
}
