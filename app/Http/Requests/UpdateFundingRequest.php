<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;

class UpdateFundingRequest extends StoreFundingRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('fundingRequest'));

        return $this->user()->can('update', $this->route('fundingRequest'));
    }
}
