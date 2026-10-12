<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerId' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'termMonths' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'status' => ['prohibited'],
            'applicationId' => ['prohibited'],
        ];
    }
}
