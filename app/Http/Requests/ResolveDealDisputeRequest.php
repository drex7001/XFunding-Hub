<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResolveDealDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('dispute'));

        return Gate::allows('resolve', $this->route('dispute'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['outcome' => ['required', Rule::in(['settled', 'closed_unsettled'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'status' => ['prohibited'], 'resolved_by' => ['prohibited'], 'settled_at' => ['prohibited']];
    }
}
