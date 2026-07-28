<x-layout title="Settlement disputes">
    <div class="page-heading"><div><span class="eyebrow">OPERATIONS REVIEW</span><h1>Settlement disputes.</h1><p class="muted">Review institution attestations and record a reasoned outcome. The platform does not execute or reverse transfers.</p></div></div>
    <section class="panel">
        @forelse ($records as $record)
            <div class="review-row"><div><strong>Deal {{ $record->deal_id }}</strong><small>{{ $record->created_at->format('d M Y, H:i') }} UTC · {{ ucwords(str_replace('_', ' ', $record->status)) }}</small></div>
                <form method="post" action="{{ route('disputes.review', $record) }}">@csrf<label for="reason-{{ $record->id }}">Access reason</label><input id="reason-{{ $record->id }}" name="reason" required minlength="8" maxlength="500"><button class="button secondary">Review dispute</button></form>
            </div>
        @empty<p class="empty">No settlement disputes recorded.</p>@endforelse
        {{ $records->links() }}
    </section>
</x-layout>
