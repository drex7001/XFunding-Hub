<x-layout title="Dispute review">
    <x-page-header
        title="Review settlement evidence"
        :back="route('disputes.operations')"
        back-label="Settlement disputes"
        :status="$record->status === \App\DealDisputeStatus::Open ? 'disputed' : $record->status"
        :status-label="ucfirst(str_replace('_', ' ', $data['status']))"
        :meta="'Deal '.strtoupper(substr($data['dealId'], -8)).' · This access is audited'"
    />

    @if ($record->status === \App\DealDisputeStatus::Open)
        <x-next-step :title="$data['canSettle'] ? 'Record the settled outcome' : 'Record the closing outcome'">
            @unless ($data['canSettle'])
                <p>A settled outcome requires both current approved confirmations from eligible institutions, with no instruction review pending.@if ($data['pendingInstructionReview']) Closing supersedes the pending instruction review.@endif</p>
            @endunless
            <form method="post" action="{{ route('disputes.resolve', $record) }}">
                @csrf
                <label for="resolution-reason">Resolution reason and evidence summary</label>
                <textarea id="resolution-reason" name="reason" required minlength="10" maxlength="2000" rows="3"></textarea>
                <div class="btn-row">
                    @if ($data['canSettle'])
                        <button class="btn btn--primary" name="outcome" value="settled">Resolve as settled</button>
                    @else
                        <button class="btn btn--danger" name="outcome" value="closed_unsettled">Close without confirmed settlement</button>
                    @endif
                </div>
            </form>
        </x-next-step>
    @endif

    <div class="grid-even">
        <section class="panel">
            <h2>Reported issue</h2>
            <p class="long-text">{{ $data['description'] }}</p>
            @if ($data['resolutionNote'])
                <h3 class="subhead">Resolution</h3>
                <p class="long-text">{{ $data['resolutionNote'] }}</p>
            @endif
        </section>
        <section class="panel">
            <h2>Institution attestations</h2>
            @forelse ($data['confirmations'] as $confirmation)
                <div class="row">
                    <div class="row__text">
                        <strong>{{ ucfirst($confirmation['side']) }}</strong>
                        <small><x-when :at="$confirmation['settlementAt']" /> · {{ $confirmation['currentVersion'] ? 'Current instructions' : 'Previous instructions' }}</small>
                        @if ($confirmation['reference'])
                            <small>{{ $confirmation['reference'] }}</small>
                        @endif
                        @if ($confirmation['note'])
                            <small>{{ $confirmation['note'] }}</small>
                        @endif
                    </div>
                    <x-status :value="$confirmation['status']" />
                </div>
            @empty
                <p class="empty-line">Neither institution has supplied a confirmation.</p>
            @endforelse
            <p class="muted">Bank attestations are not independent transfer verification.</p>
        </section>
    </div>
</x-layout>
