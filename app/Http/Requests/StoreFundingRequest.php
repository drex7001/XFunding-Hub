<?php

namespace App\Http\Requests;

use App\Models\FundingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFundingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FundingRequest::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', $value) && FundingRequest::amountToMinor($value) < 1) {
                        $fail('The amount must be greater than zero.');
                    }
                }],
            'currency' => ['required', Rule::in(config('marketplace.currencies'))],
            'tenor_days' => ['required', 'integer', 'min:1', 'max:'.config('marketplace.max_tenor_days')],
            'settlement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'bidding_deadline' => ['required', 'date', 'after:now'],
            'day_count' => ['required', Rule::in(config('marketplace.day_counts'))],
            'borrower_background' => ['required', 'string', 'min:20', 'max:5000'],
            'bank_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'status' => ['prohibited'],
            'rate_basis' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'contributor_ids' => ['prohibited'],
            'borrower_profile' => ['prohibited'],
            'version' => ['prohibited'],
            'published_at' => ['prohibited'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->hasAny(['bidding_deadline', 'settlement_date'])
                && CarbonImmutable::parse($this->input('bidding_deadline'), 'UTC')->utc()->toDateString() > $this->input('settlement_date')) {
                $validator->errors()->add('bidding_deadline', 'The bidding deadline must be on or before the settlement date (UTC).');
            }
        }];
    }
}
