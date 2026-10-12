<?php

namespace App\Http\Requests;

use App\States\LoanApplicationState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoanApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(array_keys(LoanApplicationState::classesByName()))]];
    }
}
