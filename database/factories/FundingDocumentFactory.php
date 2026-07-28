<?php

namespace Database\Factories;

use App\Models\FundingDocument;
use App\Models\FundingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FundingDocumentFactory extends Factory
{
    protected $model = FundingDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'funding_request_id' => FundingRequest::factory(), 'uploaded_by' => User::factory(),
            'original_name' => 'financial-report.pdf', 'path' => 'funding/'.Str::ulid().'.pdf',
            'mime_type' => 'application/pdf', 'size' => 1024, 'sha256' => hash('sha256', 'synthetic financial report'),
        ];
    }
}
