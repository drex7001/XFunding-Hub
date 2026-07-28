<?php

namespace App\Http\Requests;

use App\Models\Bank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('admin_email'))) {
            $this->merge(['admin_email' => mb_strtolower(trim($this->input('admin_email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', Bank::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/'],
            'registration_number' => ['required', 'string', 'max:100', 'unique:banks'],
            'requested_capabilities' => ['required', 'array', 'min:1', 'max:2'],
            'requested_capabilities.*' => ['required', Rule::in(['borrower', 'lender']), 'distinct'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
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
