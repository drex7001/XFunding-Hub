<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'bank_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'status' => ['prohibited'],
            'can_borrow' => ['prohibited'],
            'can_lend' => ['prohibited'],
            'active' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
        ];
    }
}
