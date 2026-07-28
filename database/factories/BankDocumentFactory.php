<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankDocument> */
class BankDocumentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['bank_id' => Bank::factory(), 'uploaded_by' => User::factory(), 'original_name' => 'audit-report.pdf', 'path' => 'onboarding/'.fake()->uuid().'.pdf', 'mime_type' => 'application/pdf', 'size' => 1024];
    }
}
