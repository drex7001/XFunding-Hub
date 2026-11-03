<x-layout :title="$record ? 'Edit offer' : 'Prepare an offer'">
    <x-page-header
        :title="$record ? 'Edit offer' : 'Prepare an offer'"
        :eyebrow="$fundingRequest->borrower_profile['name'].' · '.$fundingRequest->title"
        :back="$record ? route('offers.show', $record) : route('funding.show', $fundingRequest)"
        :back-label="$record ? 'Back to the offer' : 'Back to the request'"
    />

    <div class="facts">
        <x-fact label="Full request coverage" :hint="$fundingRequest->tenor_days.' days · '.$fundingRequest->day_count"><x-money :currency="$fundingRequest->currency" :amount="$fundingRequest->amount" /></x-fact>
        <x-fact label="Bidding deadline"><x-when :at="$fundingRequest->bidding_deadline" /></x-fact>
        <x-fact label="Expected settlement"><x-when :at="$fundingRequest->settlement_date" date-only /></x-fact>
    </div>

    @if ($instructions->isEmpty())
        <x-next-step title="Approve settlement instructions first" kicker="Set up first">
            <p>A lender maker must propose instructions and a different approver must approve them before creating an offer.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('instructions.index') }}">Manage instructions</a></div>
        </x-next-step>
    @else
        <div class="grid-form">
            <section class="panel">
                <form method="post" action="{{ $record ? route('offers.update', $record) : route('offers.store', $fundingRequest) }}">
                    @csrf
                    @if ($record)
                        @method('PATCH')
                        <div class="alert alert--info">Saving changes supersedes the previous approval proposal.</div>
                    @endif
                    <label for="rate">Annualized simple interest rate (%)</label>
                    <input id="rate" name="rate" inputmode="decimal" value="{{ old('rate', $record?->rate) }}" placeholder="8.1250" required>
                    <span class="hint">0–100 percent, up to 4 decimal places, without commas.</span>
                    <div class="form-row">
                        <div>
                            <label for="collateral">Collateral requirement</label>
                            <select id="collateral" name="collateral">
                                @foreach (config('marketplace.collateral_types') as $value)
                                    <option value="{{ $value }}" @selected(old('collateral', $record?->collateral) === $value)>{{ ucfirst(str_replace('_', ' ', $value)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="interest_payment">Interest payment</label>
                            <select id="interest_payment" name="interest_payment">
                                @foreach (config('marketplace.interest_payments') as $value)
                                    <option value="{{ $value }}" @selected(old('interest_payment', $record?->interest_payment) === $value)>{{ ucfirst(str_replace('_', ' ', $value)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <label for="valid_until">Offer valid until (UTC)</label>
                    <input id="valid_until" type="datetime-local" name="valid_until" value="{{ old('valid_until', $record?->valid_until->format('Y-m-d\TH:i') ?? $fundingRequest->settlement_date->endOfDay()->format('Y-m-d\TH:i')) }}" required>
                    <span class="hint">Cover the bidding deadline and end on or before settlement day. Selected terms are reserved until this deadline, and an offer cannot be selected within {{ config('marketplace.minimum_fee_window_minutes') }} minutes of expiry.</span>
                    <label for="settlement_instruction_id">Approved instruction version</label>
                    <select id="settlement_instruction_id" name="settlement_instruction_id" required>
                        @foreach ($instructions as $instruction)
                            <option value="{{ $instruction->id }}" @selected(old('settlement_instruction_id', $record?->settlement_instruction_id) === $instruction->id)>Version {{ $instruction->version }} · {{ strtoupper($instruction->details['channel']) }}</option>
                        @endforeach
                    </select>
                    <div class="btn-row">
                        <button class="btn btn--primary" name="submit_for_approval" value="1">Save and send for approval</button>
                        <button class="btn btn--secondary">Save as draft</button>
                    </div>
                </form>
            </section>
            <aside class="panel">
                <h2>What the borrower sees</h2>
                <p class="muted">Only structured terms: rate, collateral, interest payment and validity, as an anonymous offer.</p>
                <h3 class="subhead">What stays private</h3>
                <p class="muted">Your bank name, contacts and banking instructions, until the platform fee is verified. Non-selected lenders are never disclosed.</p>
            </aside>
        </div>
    @endif
</x-layout>
