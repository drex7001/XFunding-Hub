<x-layout :title="$record ? 'Edit funding request' : 'New funding request'">
    <x-page-header
        :title="$record ? 'Edit funding request' : 'New funding request'"
        :back="$record ? route('funding.show', $record) : route('funding.index')"
        :back-label="$record ? 'Back to the request' : 'Funding requests'"
    />

    <div class="grid-form">
        <section class="panel">
            <form method="post" action="{{ $record ? route('funding.update', $record) : route('funding.store') }}">
                @csrf
                @if ($record)
                    @method('PATCH')
                    <div class="alert alert--info">Saving changes supersedes a pending publication approval. Submit the updated draft for a new review.</div>
                @endif
                <div class="form-section">
                    <h2>Terms</h2>
                    <label for="title">Request title</label>
                    <input id="title" name="title" value="{{ old('title', $record?->title) }}" maxlength="180" placeholder="Short-term treasury liquidity" required>
                    <div class="form-row">
                        <div>
                            <label for="amount">Required amount</label>
                            <input id="amount" name="amount" type="text" inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?" value="{{ old('amount', $record?->amount) }}" placeholder="250000000.00" required>
                            <span class="hint">Up to 2 decimal places, without commas.</span>
                        </div>
                        <div>
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency" required>
                                @foreach (config('marketplace.currencies') as $currency)
                                    <option value="{{ $currency }}" @selected(old('currency', $record?->currency ?? 'LKR') === $currency)>{{ $currency }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="tenor">Tenor in days</label>
                            <input id="tenor" name="tenor_days" type="number" min="1" max="{{ config('marketplace.max_tenor_days') }}" value="{{ old('tenor_days', $record?->tenor_days ?? 7) }}" required>
                        </div>
                        <div>
                            <label for="day-count">Day-count convention</label>
                            <select id="day-count" name="day_count" required>
                                @foreach (config('marketplace.day_counts') as $dayCount)
                                    <option value="{{ $dayCount }}" @selected(old('day_count', $record?->day_count ?? 'ACT/365F') === $dayCount)>{{ $dayCount }}</option>
                                @endforeach
                            </select>
                            <span class="hint">Offers quote an annualized simple rate.</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div>
                            <label for="deadline">Bidding deadline (UTC)</label>
                            <input id="deadline" name="bidding_deadline" type="datetime-local" value="{{ old('bidding_deadline', $record?->bidding_deadline?->format('Y-m-d\TH:i')) }}" required>
                            <span class="hint">In the future, on or before settlement day.</span>
                        </div>
                        <div>
                            <label for="settlement">Expected settlement date</label>
                            <input id="settlement" name="settlement_date" type="date" min="{{ today()->toDateString() }}" value="{{ old('settlement_date', $record?->settlement_date?->toDateString()) }}" required>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h2>Borrower background</h2>
                    <label for="background">Background and financial history</label>
                    <textarea id="background" name="borrower_background" rows="6" minlength="20" maxlength="5000" required>{{ old('borrower_background', $record?->borrower_background) }}</textarea>
                    <span class="hint">Verified lenders read this with your bank identity after publication.</span>
                </div>
                <div class="btn-row">
                    <button class="btn btn--primary">{{ $record ? 'Save changes' : 'Save draft and continue' }}</button>
                    <a class="btn btn--secondary" href="{{ $record ? route('funding.show', $record) : route('funding.index') }}">Cancel</a>
                </div>
            </form>
        </section>
        <aside class="panel">
            <h2>What happens next</h2>
            <ol class="timeline">
                <li><strong>Save the draft</strong><small>Terms stay editable until they are published.</small></li>
                <li><strong>Attach financial PDFs</strong><small>At least one is required, up to 10 MB each.</small></li>
                <li><strong>Independent approval</strong><small>An approver who did not work on the request publishes it.</small></li>
                <li><strong>Compare blind offers</strong><small>Lenders stay anonymous until the fee is verified.</small></li>
            </ol>
        </aside>
    </div>
</x-layout>
