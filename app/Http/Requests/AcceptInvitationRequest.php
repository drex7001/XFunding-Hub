<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'bank_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'status' => ['prohibited'],
            'can_borrow' => ['prohibited'],
            'can_lend' => ['prohibited'],
            'active' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'email' => ['prohibited'],
            'roles' => ['prohibited'],
        ];
    }
}
