<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StorePlatformAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('managePlatformAccounts', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'password' => ['prohibited'], 'is_platform_admin' => ['prohibited'], 'active' => ['prohibited'], 'bank_id' => ['prohibited'],
        ];
    }
}
