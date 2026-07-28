<?php

namespace App\Http\Requests;

use App\Models\SettlementConfirmation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSettlementConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('deal'));

        return Gate::allows('create', [SettlementConfirmation::class, $this->route('deal')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['settlement_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'min:3', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'], 'status' => ['prohibited'], 'bank_id' => ['prohibited'],
            'proposed_by' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'settlement_instruction_id' => ['prohibited'],
            'approved_slot' => ['prohibited'], 'pending_slot' => ['prohibited']];
    }
}
