<?php

namespace Database\Factories;

use App\BankRole;
use App\Models\Bank;
use App\Models\BankInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BankInvitation> */
class BankInvitationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['bank_id' => Bank::factory(), 'invited_by' => User::factory()->platformAdmin(), 'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'roles' => [BankRole::Maker->value], 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDays(3)];
    }
}
