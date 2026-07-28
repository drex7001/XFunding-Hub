<x-layout title="Compare offers">
    <a class="small-link" href="{{ route('funding.show', $record) }}">← Funding request</a>
    <div class="page-heading"><div><span class="eyebrow">BORROWER TREASURY</span><h1>Compare your offers.</h1><p class="muted">{{ $record->title }} · {{ $record->currency }} {{ $record->amount }} · {{ $record->tenor_days }} days</p></div></div>
    <div class="notice">Compare the complete terms. Lender identities, contacts and settlement instructions remain locked until verified platform fee payment.</div>
    @if ($data['deal'])
        <section class="panel"><h2>{{ $data['deal']['terms']['label'] }} selected.</h2><p class="muted">Your selected terms are fixed. Other offers are closed.</p><a class="button" href="{{ route('deals.show', $data['deal']['id']) }}">View selected deal →</a></section>
    @endif
    <div class="request-grid">
        @forelse ($data['offers'] as $offer)
            <section class="panel request-card"><div class="panel-heading"><span class="eyebrow">ANONYMOUS LENDER</span><span class="badge active">Available</span></div><h2>{{ $offer['label'] }}</h2><strong class="request-amount">{{ $offer['rate'] }}%</strong><p class="muted">Annual simple rate · {{ $record->day_count }}</p><dl><dt>Collateral</dt><dd>{{ ucwords(str_replace('_', ' ', $offer['collateral'])) }}</dd><dt>Interest payment</dt><dd>{{ ucwords(str_replace('_', ' ', $offer['interestPayment'])) }}</dd><dt>Offer validity (UTC)</dt><dd>{{ \Carbon\CarbonImmutable::parse($offer['validUntil'])->format('d M Y, H:i') }}</dd></dl>
                @if ($data['canPropose'])
                    <form method="post" action="{{ route('selection.store', $record) }}">
                        @csrf
                        <input type="hidden" name="offer_token" value="{{ $offer['token'] }}"><button class="button">Propose {{ $offer['label'] }}</button>
                    </form>
                @endif
            </section>
        @empty
            @if (!$data['deal'])
                <section class="panel empty-state"><h2>No available offers.</h2><p class="muted">Independently approved, valid offers appear here while your bidding window is open.</p></section>
            @endif
        @endforelse
    </div>
    @if ($data['pendingSelection'])
        <section class="panel"><h2>Selection approval pending.</h2><p>{{ $data['pendingSelection']['offer']['label'] }} · {{ $data['pendingSelection']['offer']['rate'] }}% · {{ ucwords(str_replace('_', ' ', $data['pendingSelection']['offer']['collateral'])) }} · {{ ucwords(str_replace('_', ' ', $data['pendingSelection']['offer']['interestPayment'])) }}</p><p class="muted">Verify the terms above and the offer validity before approving.</p>
            @if ($data['pendingSelection']['canReview'])
                <form method="post" action="{{ route('selection.review', ['fundingRequest' => $record, 'selection' => $data['pendingSelection']['id']]) }}">
                    @csrf
                    <label for="reason">Review note (required when rejecting)</label><textarea id="reason" name="reason" rows="2" maxlength="500"></textarea><button class="button" name="decision" value="approve">Approve selection</button><button class="button secondary" name="decision" value="reject">Reject selection</button>
                </form>
            @else
                <p class="muted">A different borrower treasury approver must review this selection.</p>
            @endif
        </section>
    @endif
</x-layout>
