<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementDisclosureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $lender = $this->instruction_snapshot;
        $borrower = $this->borrower_instruction_snapshot;

        return [
            'dealId' => $this->id,
            'lender' => $this->institution($lender),
            'instructions' => $this->instructionFields($lender),
            'borrower' => $borrower !== null ? $this->institution($borrower) : null,
            'borrowerInstructions' => $borrower !== null ? $this->instructionFields($borrower) : null,
            'unlockedAt' => $this->unlocked_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    private function institution(array $snapshot): array
    {
        return [
            'name' => $snapshot['bank']['name'],
            'countryCode' => $snapshot['bank']['country_code'],
            'registrationNumber' => $snapshot['bank']['registration_number'],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function instructionFields(array $snapshot): array
    {
        $details = $snapshot['details'];

        return [
            'version' => $snapshot['version'],
            'channel' => $details['channel'],
            'accountName' => $details['account_name'],
            'accountNumber' => $details['account_number'],
            'bic' => $details['bic'] ?? null,
            'rtgsCode' => $details['rtgs_code'] ?? null,
            'contactName' => $details['contact_name'], 'contactEmail' => $details['contact_email'],
        ];
    }
}
