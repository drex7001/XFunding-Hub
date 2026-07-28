<x-layout :title="$record ? 'Edit offer' : 'New offer'">
    <a class="small-link" href="{{ $record ? route('offers.show', $record) : route('funding.show', $fundingRequest) }}">← Back to {{ $record ? 'offer' : 'request' }}</a>
    <div class="page-heading"><div><span class="eyebrow">LENDER TREASURY</span><h1>{{ $record ? 'Refine your offer.' : 'Prepare a liquidity offer.' }}</h1><p class="muted">{{ $fundingRequest->borrower_profile['name'] }} · {{ $fundingRequest->title }}</p></div></div>
    <div class="notice">Full request coverage: {{ $fundingRequest->currency }} {{ $fundingRequest->amount }} · {{ $fundingRequest->tenor_days }} days · {{ $fundingRequest->day_count }}. Your identity remains hidden from the borrower until a verified platform fee.</div>
    @if ($instructions->isEmpty())
        <section class="panel"><h2>Approve settlement instructions first.</h2><p class="muted">A lender maker must propose instructions and a different approver must approve them before creating an offer.</p><a class="button" href="{{ route('instructions.index') }}">Manage instructions →</a></section>
    @else
        <section class="panel form-panel"><form method="post" action="{{ $record ? route('offers.update', $record) : route('offers.store', $fundingRequest) }}">
            @csrf
            @if ($record)
                @method('PATCH')
                <p class="muted">Saving changes supersedes the previous approval proposal.</p>
            @endif
            <label for="rate">Annualized simple interest rate (%)</label><input id="rate" name="rate" inputmode="decimal" value="{{ old('rate', $record?->rate) }}" placeholder="8.1250" required><small>0–100 percent, up to 4 decimal places. No commas.</small>
            <div class="form-columns"><div><label for="collateral">Collateral requirement</label><select id="collateral" name="collateral">
                @foreach (config('marketplace.collateral_types') as $value)
                    <option value="{{ $value }}" @selected(old('collateral', $record?->collateral) === $value)>{{ ucwords(str_replace('_', ' ', $value)) }}</option>
                @endforeach
            </select></div><div><label for="interest_payment">Interest payment</label><select id="interest_payment" name="interest_payment">
                @foreach (config('marketplace.interest_payments') as $value)
                    <option value="{{ $value }}" @selected(old('interest_payment', $record?->interest_payment) === $value)>{{ ucwords(str_replace('_', ' ', $value)) }}</option>
                @endforeach
            </select></div></div>
            <label for="valid_until">Offer valid until (UTC)</label><input id="valid_until" type="datetime-local" name="valid_until" value="{{ old('valid_until', $record?->valid_until->format('Y-m-d\TH:i') ?? $fundingRequest->settlement_date->endOfDay()->format('Y-m-d\TH:i')) }}" required><small>Cover the bidding deadline and end on or before settlement day. Selected terms are reserved until this deadline.</small>
            <label for="settlement_instruction_id">Approved instruction version</label><select id="settlement_instruction_id" name="settlement_instruction_id" required>
                @foreach ($instructions as $instruction)
                    <option value="{{ $instruction->id }}" @selected(old('settlement_instruction_id', $record?->settlement_instruction_id) === $instruction->id)>Version {{ $instruction->version }} · {{ strtoupper($instruction->details['channel']) }}</option>
                @endforeach
            </select><p class="muted">The borrower sees structured financial terms. Your contacts and banking instructions remain private.</p>
            <button class="button">{{ $record ? 'Save updated offer' : 'Create offer draft' }}</button>
        </form></section>
    @endif
</x-layout>
