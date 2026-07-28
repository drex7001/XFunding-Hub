<x-layout :title="$record ? 'Edit funding draft' : 'New funding request'">
    <a class="small-link" href="{{ $record ? route('funding.show', $record) : route('funding.index') }}">← Back to {{ $record ? 'request' : 'requests' }}</a>
    <div class="page-heading"><div><span class="eyebrow">BORROWER TREASURY</span><h1>{{ $record ? 'Refine your request.' : 'Create a funding request.' }}</h1><p class="muted">Your bank identity and attached financial documents become visible to verified lender treasury users after publication.</p></div></div>
    <section class="panel funding-form">
        <form method="post" action="{{ $record ? route('funding.update', $record) : route('funding.store') }}">
            @csrf
            @if ($record)
                @method('PATCH')
                <div class="notice">Saving changes supersedes a pending publication approval. Submit the updated draft for a new review.</div>
            @endif
            <label for="title">Request title</label><input id="title" name="title" value="{{ old('title', $record?->title) }}" maxlength="180" placeholder="Short-term treasury liquidity" required>
            <div class="form-grid">
                <div><label for="amount">Required amount</label><input id="amount" name="amount" type="text" inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?" value="{{ old('amount', $record?->amount) }}" placeholder="250000000.00" required><small>Use up to 2 decimal places, without commas.</small></div>
                <div><label for="currency">Currency</label><select id="currency" name="currency" required>
                    @foreach (config('marketplace.currencies') as $currency)
                        <option value="{{ $currency }}" @selected(old('currency', $record?->currency ?? 'LKR') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select></div>
            </div>
            <div class="form-grid">
                <div><label for="tenor">Tenor in days</label><input id="tenor" name="tenor_days" type="number" min="1" max="{{ config('marketplace.max_tenor_days') }}" value="{{ old('tenor_days', $record?->tenor_days ?? 7) }}" required></div>
                <div><label for="settlement">Expected settlement date</label><input id="settlement" name="settlement_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('settlement_date', $record?->settlement_date?->toDateString()) }}" required></div>
            </div>
            <div class="form-grid">
                <div><label for="deadline">Bidding deadline (UTC)</label><input id="deadline" name="bidding_deadline" type="datetime-local" value="{{ old('bidding_deadline', $record?->bidding_deadline?->format('Y-m-d\TH:i')) }}" required><small>Must be in the future, on or before settlement day.</small></div>
                <div><label for="day-count">Day-count convention</label><select id="day-count" name="day_count" required>
                    @foreach (config('marketplace.day_counts') as $dayCount)
                        <option value="{{ $dayCount }}" @selected(old('day_count', $record?->day_count ?? 'ACT/365F') === $dayCount)>{{ $dayCount }}</option>
                    @endforeach
                </select><small>Offers will quote an annualized simple rate.</small></div>
            </div>
            <label for="background">Borrower background and financial history</label><textarea id="background" name="borrower_background" rows="6" minlength="20" maxlength="5000" required>{{ old('borrower_background', $record?->borrower_background) }}</textarea>
            <p class="muted">Save the draft, attach financial PDFs, then request approval by an independent treasury approver.</p>
            <button class="button">{{ $record ? 'Save updated draft' : 'Create draft' }}</button>
        </form>
    </section>
</x-layout>
