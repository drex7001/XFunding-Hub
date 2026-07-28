<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('bank'));

        return $this->user()->can('update', $this->route('bank'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'registration_number' => ['required', 'string', 'max:100', Rule::unique('banks')->ignore($this->route('bank'))],
            'requested_capabilities' => ['required', 'array', 'min:1', 'max:2'],
            'requested_capabilities.*' => ['required', Rule::in(['borrower', 'lender']), 'distinct'],
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
