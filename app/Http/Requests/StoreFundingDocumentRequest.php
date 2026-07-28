<?php

namespace App\Http\Requests;

use App\Models\FundingDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreFundingDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('fundingRequest'));

        return $this->user()->can('create', [FundingDocument::class, $this->route('fundingRequest')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.config('marketplace.max_document_kilobytes')],
            'bank_id' => ['prohibited'], 'funding_request_id' => ['prohibited'], 'uploaded_by' => ['prohibited'],
        ];
    }
}
