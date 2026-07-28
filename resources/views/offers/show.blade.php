<x-layout title="Offer review">
    <a class="small-link" href="{{ route('offers.index') }}">← Your offers</a>
    <div class="page-heading"><div><span class="eyebrow">{{ $data['borrowerName'] }}</span><h1>{{ $data['title'] }}</h1><p class="muted">Your offer · Revision {{ $data['version'] }}</p></div><span class="badge {{ $data['status'] }}">{{ $record->currentStatus()->label() }}</span></div>
    <div class="summary-grid"><div class="summary"><span class="eyebrow">ANNUAL SIMPLE RATE</span><strong>{{ $data['rate'] }}%</strong><small>{{ $data['dayCount'] }}</small></div><div class="summary"><span class="eyebrow">FULL REQUEST COVERAGE</span><strong>{{ $data['currency'] }} {{ $data['amount'] }}</strong><small>{{ $data['tenorDays'] }} days</small></div><div class="summary"><span class="eyebrow">OFFER VALID UNTIL</span><strong>{{ $record->valid_until->format('d M Y, H:i') }}</strong><small>UTC</small></div></div>
    <div class="content-grid"><section class="panel"><h2>Offer conditions</h2><dl><dt>Collateral</dt><dd>{{ ucwords(str_replace('_', ' ', $data['collateral'])) }}</dd><dt>Interest payment</dt><dd>{{ ucwords(str_replace('_', ' ', $data['interestPayment'])) }}</dd></dl>
        @isset ($data['instructionId'])
            <a class="small-link" href="{{ route('instructions.show', $data['instructionId']) }}">Review approved instructions · Version {{ $data['instructionVersion'] }} →</a>
        @endisset
        <p class="muted">Borrowers receive an anonymous structured comparison. Competitor offers are private.</p>
    </section><section class="panel"><h2>Offer actions</h2>
        @if ($data['can']['update'])
            <a class="button secondary" href="{{ route('offers.edit', $record) }}">Edit draft</a>
        @endif
        @if ($data['can']['submit'])
            <form class="inline-form" method="post" action="{{ route('offers.submit', $record) }}">
                @csrf
                <button class="button">Submit offer for approval</button>
            </form>
        @endif
        @if ($data['pendingApproval'])
            <p class="muted">Independent approval of these terms is pending.</p>
        @endif
        @if ($data['can']['review'])
            <form method="post" action="{{ route('offers.review', ['offer' => $record, 'approval' => $data['pendingApproval']['id']]) }}">
                @csrf
                <label for="reason">Review note (required when rejecting)</label><textarea id="reason" name="reason" rows="2" maxlength="500"></textarea><button class="button" name="decision" value="approve">Approve offer</button><button class="button secondary" name="decision" value="reject">Request changes / reject</button>
            </form>
        @endif
        @if ($data['can']['withdraw'])
            <details class="separated"><summary>Withdraw offer</summary><form method="post" action="{{ route('offers.withdraw', $record) }}">
                @csrf
                <label for="withdraw-reason">Reason</label><textarea id="withdraw-reason" name="reason" minlength="5" maxlength="500" rows="2" required></textarea><button class="button secondary">Withdraw offer</button>
            </form></details>
        @endif
        @if ($record->status === \App\OfferStatus::Selected && $record->fundingRequest->deal)
            <div class="notice">This offer is reserved. Its terms and instructions cannot be changed.</div><a class="button" href="{{ route('deals.show', $record->fundingRequest->deal) }}">View selected deal →</a>
        @endif
    </section></div>
    <section class="panel"><h2>Approval history</h2>
        @forelse ($history as $approval)
            <div class="review-row"><div><strong>Revision {{ $approval->expected_version }}</strong><small>{{ $approval->reason }}</small></div><span class="badge {{ $approval->status }}">{{ ucfirst($approval->status) }}</span></div>
        @empty
            <p class="empty">No approval proposals yet.</p>
        @endforelse
    </section>
</x-layout>
