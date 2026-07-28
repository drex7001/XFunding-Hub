<x-layout title="Your offers">
    <div class="page-heading"><div><span class="eyebrow">LENDER TREASURY</span><h1>Your institution’s offers.</h1><p class="muted">Review drafts, approve terms and track your bids.</p></div>
        @can('viewMarketplace', \App\Models\FundingRequest::class)
            <a class="button" href="{{ route('marketplace') }}">Browse requests →</a>
        @endcan
    </div>
    <div class="request-grid">
        @forelse ($records as $record)
            <a class="panel request-card" href="{{ route('offers.show', $record) }}"><div class="panel-heading"><span class="eyebrow">{{ $record->fundingRequest->currency }} OFFER</span><span class="badge {{ $record->currentStatus()->value }}">{{ $record->currentStatus()->label() }}</span></div><h2>{{ $record->fundingRequest->title }}</h2><p>{{ $record->fundingRequest->borrower_profile['name'] }}</p><strong class="request-amount">{{ $record->rate }}%</strong><p class="muted">{{ $record->fundingRequest->currency }} {{ $record->fundingRequest->amount }} · {{ $record->fundingRequest->tenor_days }} days</p><div class="request-footer"><span>Valid until {{ $record->valid_until->format('d M, H:i') }} UTC</span><span>Review offer →</span></div></a>
        @empty
            <section class="panel empty-state"><h2>No offers yet.</h2><p class="muted">A lender maker can prepare an offer from a published funding request.</p></section>
        @endforelse
    </div>{{ $records->links() }}
</x-layout>
