<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReviewSettlementConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('deal'));
        abort_unless($this->route('confirmation')->deal_id === $this->route('deal')->id, 404);

        return Gate::allows('review', $this->route('confirmation'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'required_if:decision,reject', 'string', 'min:8', 'max:500'],
            'status' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'reference' => ['prohibited'], 'note' => ['prohibited']];
    }
}
