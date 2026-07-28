<x-layout title="Selected deals">
    <div class="page-heading"><div><span class="eyebrow">TREASURY WORKSPACE</span><h1>Your selected deals.</h1><p class="muted">Track platform fees and access approved settlement details.</p></div></div>
    <div class="request-grid">
        @forelse ($records as $record)
            <a class="panel request-card" href="{{ route('deals.show', $record) }}"><div class="panel-heading"><span class="eyebrow">{{ $record->terms['label'] }}</span><span class="badge {{ $record->currentStatus()->value }}">{{ ucwords(str_replace('_', ' ', $record->currentStatus()->value)) }}</span></div><h2>{{ $record->terms['borrower']['name'] }}</h2><strong class="request-amount">{{ $record->terms['rate'] }}%</strong><p class="muted">{{ $record->terms['currency'] }} {{ $record->terms['amount'] }} · {{ $record->terms['tenorDays'] }} days</p><div class="request-footer"><span>{{ $record->fee_deadline ? 'Fee deadline '.$record->fee_deadline->format('d M, H:i').' UTC' : 'Fee quote pending' }}</span><span>Review deal →</span></div></a>
        @empty
            <section class="panel empty-state"><h2>No selected deals yet.</h2><p class="muted">Approved selections appear here for the participating institutions.</p></section>
        @endforelse
    </div>{{ $records->links() }}
</x-layout>
