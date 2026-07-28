<?php

namespace App\Http\Requests;

use App\Models\SettlementInstruction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSettlementInstructionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SettlementInstruction::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['rtgs', 'swift'])],
            'account_name' => ['required', 'string', 'min:3', 'max:140'],
            'account_number' => ['required', 'string', 'regex:/^[A-Z0-9]{4,34}$/'],
            'rtgs_code' => ['nullable', 'required_if:channel,rtgs', 'string', 'regex:/^[A-Za-z0-9 .-]{2,35}$/'],
            'bic' => ['nullable', 'required_if:channel,swift', 'string', 'regex:/^[A-Z0-9]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/'],
            'contact_name' => ['required', 'string', 'max:140'],
            'contact_email' => ['required', 'email', 'max:255'],
            'bank_id' => ['prohibited'], 'created_by' => ['prohibited'], 'reviewed_by' => ['prohibited'],
            'status' => ['prohibited'], 'version' => ['prohibited'], 'bank_profile' => ['prohibited'],
        ];
    }
}
