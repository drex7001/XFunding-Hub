<?php

namespace App\Http\Requests;

use App\Models\Offer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('offer') !== null) {
            Gate::authorize('view', $this->route('offer'));

            return $this->user()->can('update', $this->route('offer'));
        }
        Gate::authorize('view', $this->route('fundingRequest'));

        return $this->user()->can('create', [Offer::class, $this->route('fundingRequest')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rate' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/', $value) && Offer::rateToUnits($value) > 1000000) {
                        $fail('The annual rate must be between 0 and 100 percent.');
                    }
                }],
            'collateral' => ['required', Rule::in(config('marketplace.collateral_types'))],
            'interest_payment' => ['required', Rule::in(config('marketplace.interest_payments'))],
            'valid_until' => ['required', 'date', 'after:now'],
            'settlement_instruction_id' => ['required', 'ulid'],
            'bank_id' => ['prohibited'], 'created_by' => ['prohibited'], 'status' => ['prohibited'],
            'funding_request_id' => ['prohibited'], 'rate_units' => ['prohibited'], 'version' => ['prohibited'],
            'borrower_token' => ['prohibited'], 'label_number' => ['prohibited'], 'active_slot' => ['prohibited'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'tenor_days' => ['prohibited'],
            'notes' => ['prohibited'], 'attachments' => ['prohibited'], 'contact_email' => ['prohibited'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('valid_until')) {
                return;
            }
            $record = $this->route('fundingRequest') ?? $this->route('offer')->fundingRequest;
            $validUntil = CarbonImmutable::parse($this->input('valid_until'), 'UTC')->utc();
            if ($validUntil->isBefore($record->bidding_deadline) || $validUntil->toDateString() > $record->settlement_date->toDateString()) {
                $validator->errors()->add('valid_until', 'Offer validity must cover the bidding deadline and end on or before settlement day (UTC).');
            }
        }];
    }
}
