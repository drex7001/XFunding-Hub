<x-layout :title="$marketplace ? 'Marketplace' : 'Funding requests'">
    <div class="page-heading">
        <div>
            <span class="eyebrow">{{ $marketplace ? 'VERIFIED INTERBANK MARKETPLACE' : 'BORROWER TREASURY' }}</span>
            <h1>{{ $marketplace ? 'Review liquidity requests.' : 'Your funding requests.' }}</h1>
            <p class="muted">{{ $marketplace ? 'Assess published borrower profiles and financial documents.' : 'Prepare requests, review approvals and track publication.' }}</p>
        </div>
        @if (!$marketplace)
            @can('create', \App\Models\FundingRequest::class)
                <a class="button" href="{{ route('funding.create') }}">New funding request +</a>
            @endcan
        @endif
    </div>
    <div class="panel-heading"><span class="tag">{{ $records->total() }} requests</span><span class="muted">{{ $marketplace ? 'Open requests from eligible borrower banks' : 'Only your institution’s requests' }}</span></div>
    <div class="request-grid">
        @forelse ($records as $record)
            <a class="panel request-card" href="{{ route('funding.show', $record) }}">
                <div class="panel-heading"><span class="eyebrow">{{ $record->currency }} LIQUIDITY</span><span class="badge {{ $record->currentStatus()->value }}">{{ $record->currentStatus()->label() }}</span></div>
                <h2>{{ $record->title }}</h2>
                @if ($marketplace)
                    <p class="borrower-name">{{ $record->borrower_profile['name'] }}</p>
                @endif
                <strong class="request-amount">{{ $record->currency }} {{ $record->amount }}</strong>
                <div class="request-meta"><span>{{ $record->tenor_days }} days</span><span>Settlement {{ $record->settlement_date->format('d M Y') }}</span><span>{{ $record->documents->count() }} financial files</span></div>
                <div class="request-footer"><span>Bid deadline {{ $record->bidding_deadline->format('d M, H:i') }} UTC</span><span>Review request →</span></div>
            </a>
        @empty
            <section class="panel empty-state"><h2>{{ $marketplace ? 'No open requests right now.' : 'No funding requests yet.' }}</h2><p class="muted">{{ $marketplace ? 'Published requests appear here while their bidding window is open.' : 'A treasury maker can create a draft and attach the borrower’s financial documents.' }}</p></section>
        @endforelse
    </div>
    <div class="pagination">{{ $records->links() }}</div>
</x-layout>
