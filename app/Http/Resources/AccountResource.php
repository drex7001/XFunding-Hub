<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'platformAdmin' => $this->is_platform_admin, 'roles' => $this->membership?->roles ?? [], 'bank' => $this->membership ? new BankResource($this->membership->bank) : null];
    }
}
