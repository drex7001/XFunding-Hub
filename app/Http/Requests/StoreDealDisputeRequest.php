<?php

namespace App\Http\Requests;

use App\Models\DealDispute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDealDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('deal'));

        return Gate::allows('create', [DealDispute::class, $this->route('deal')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['description' => ['required', 'string', 'min:10', 'max:2000'], 'status' => ['prohibited'],
            'bank_id' => ['prohibited'], 'opened_by' => ['prohibited'], 'open_slot' => ['prohibited']];
    }
}
