<?php

namespace App\Http\Requests;

use App\Models\BankDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('bank'));

        return $this->user()->can('create', [BankDocument::class, $this->route('bank')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf', 'max:10240'],
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
