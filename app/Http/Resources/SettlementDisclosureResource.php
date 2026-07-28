<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementDisclosureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->instruction_snapshot;
        $details = $snapshot['details'];

        return [
            'dealId' => $this->id,
            'lender' => ['name' => $snapshot['bank']['name'], 'countryCode' => $snapshot['bank']['country_code'], 'registrationNumber' => $snapshot['bank']['registration_number']],
            'instructions' => [
                'version' => $snapshot['version'], 'channel' => $details['channel'], 'accountName' => $details['account_name'],
                'accountNumber' => $details['account_number'], 'bic' => $details['bic'] ?? null, 'rtgsCode' => $details['rtgs_code'] ?? null,
                'contactName' => $details['contact_name'], 'contactEmail' => $details['contact_email'],
            ],
            'unlockedAt' => $this->unlocked_at->toIso8601String(),
        ];
    }
}
