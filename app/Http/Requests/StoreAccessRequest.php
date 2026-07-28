<?php

namespace App\Http\Requests;

use App\BankRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->membership !== null && $this->user()->can('manageMembers', $this->user()->membership->bank);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:4'],
            'roles.*' => ['required', Rule::enum(BankRole::class), 'distinct'],
            'name' => [$this->route('membership') ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$this->route('membership') ? 'sometimes' : 'required', 'email', 'max:255', 'unique:users,email'],
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
