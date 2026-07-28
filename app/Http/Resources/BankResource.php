<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'countryCode' => $this->country_code, 'registrationNumber' => $this->registration_number, 'status' => $this->status->value, 'requestedCapabilities' => $this->requested_capabilities, 'canBorrow' => $this->can_borrow, 'canLend' => $this->can_lend];
    }
}
