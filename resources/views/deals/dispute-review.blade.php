<x-layout title="Dispute review">
    <a class="small-link" href="{{ route('disputes.operations') }}">← Settlement disputes</a>
    <div class="page-heading"><div><span class="eyebrow">AUDITED ACCESS</span><h1>Review settlement evidence.</h1><p class="muted">Deal {{ $data['dealId'] }}</p></div><span class="badge disputed">{{ ucwords(str_replace('_', ' ', $data['status'])) }}</span></div>
    <section class="panel"><h2>Reported issue</h2><p>{{ $data['description'] }}</p>
        @if ($data['resolutionNote'])<p>{{ $data['resolutionNote'] }}</p>@endif
    </section>
    <section class="panel"><h2>Institution attestations</h2>
        @forelse ($data['confirmations'] as $confirmation)
            <div class="review-row"><div><strong>{{ ucfirst($confirmation['side']) }} · {{ ucfirst($confirmation['status']) }}</strong><small>{{ $confirmation['settlementAt'] }} · {{ $confirmation['currentVersion'] ? 'Current instructions' : 'Previous instructions' }}</small><p>{{ $confirmation['reference'] }}</p><p>{{ $confirmation['note'] }}</p></div></div>
        @empty<p class="muted">Neither institution has supplied a confirmation.</p>@endforelse
        <p class="muted">Bank attestations are not independent transfer verification. Both current approved confirmations are required for a settled outcome.</p>
    </section>
    @if ($data['status'] === 'open')
        <section class="panel"><h2>Record resolution</h2><form method="post" action="{{ route('disputes.resolve', $record) }}">
            @csrf
            <label for="resolution-reason">Resolution reason and evidence summary</label><textarea id="resolution-reason" name="reason" required minlength="10" maxlength="2000" rows="3"></textarea>
            @if ($data['canSettle'])<button class="button" name="outcome" value="settled">Resolve as settled</button>
            @else<button class="button secondary" name="outcome" value="closed_unsettled">Close without confirmed settlement</button>@endif
        </form></section>
    @endif
</x-layout>
