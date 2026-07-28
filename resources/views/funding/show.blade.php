<x-layout title="Funding request">
    <a class="small-link" href="{{ $owned ? route('funding.index') : route('marketplace') }}">← {{ $owned ? 'Your requests' : 'Marketplace' }}</a>
    <div class="page-heading"><div><span class="eyebrow">{{ $data['borrower']['name'] }}</span><h1>{{ $record->title }}</h1><p class="muted">Request {{ $record->id }}</p></div><span class="badge {{ $record->currentStatus()->value }}">{{ $record->currentStatus()->label() }}</span></div>
    <div class="funding-toolbar">
        @if ($owned)
            <a class="button secondary" href="{{ route('funding.offers', $record) }}">Compare offers →</a>
        @endif
        @can('create', [\App\Models\Offer::class, $record])
            <a class="button" href="{{ route('offers.create', $record) }}">Prepare an offer →</a>
        @endcan
        @if ($record->deal)
            @can('view', $record->deal)
                <a class="button secondary" href="{{ route('deals.show', $record->deal) }}">View selected deal →</a>
            @endcan
        @endif
    </div>
    <div class="summary-grid">
        <div class="summary"><span class="eyebrow">REQUIRED LIQUIDITY</span><strong>{{ $record->currency }} {{ $record->amount }}</strong><small>{{ $record->tenor_days }} day tenor</small></div>
        <div class="summary"><span class="eyebrow">EXPECTED SETTLEMENT</span><strong>{{ $record->settlement_date->format('d M Y') }}</strong><small>{{ $record->day_count }} · Annualized simple rate</small></div>
        <div class="summary"><span class="eyebrow">BIDDING DEADLINE</span><strong>{{ $record->bidding_deadline->format('d M Y, H:i') }}</strong><small>UTC · {{ $record->currentStatus()->label() }}</small></div>
    </div>
    @if ($record->status === \App\FundingRequestStatus::Open)
        <div class="notice">Published financial terms, borrower profile and document set are fixed for this request.</div>
    @endif
    <div class="content-grid">
        <section class="panel">
            <div class="panel-heading"><h2>Borrower profile</h2><span class="tag">{{ $record->published_at ? 'Published snapshot' : 'Review profile' }}</span></div>
            <dl><dt>Bank name</dt><dd>{{ $data['borrower']['name'] }}</dd><dt>Country / registration</dt><dd>{{ $data['borrower']['countryCode'] }} / {{ $data['borrower']['registrationNumber'] }}</dd></dl>
            <h2>Background and financial history</h2><p class="long-text">{{ $data['borrower']['background'] }}</p>
        </section>
        <section class="panel">
            <div class="panel-heading"><h2>Financial documents</h2><span class="tag">{{ $record->documents->count() }} files</span></div>
            @forelse ($record->documents as $document)
                <div class="document-row"><span class="document-icon">PDF</span><div><strong>{{ $document->original_name }}</strong><small>{{ number_format($document->size / 1024) }} KB</small></div><a class="text-button" href="{{ route('funding.documents.show', $document) }}">Download ↓</a>
                    @can('delete', $document)
                        <form method="post" action="{{ route('funding.documents.destroy', $document) }}">
                            @csrf
                            @method('DELETE')
                            <button class="text-button danger">Remove</button>
                        </form>
                    @endcan
                </div>
            @empty
                <p class="empty">Attach at least one financial PDF before submitting for approval.</p>
            @endforelse
            @can('create', [\App\Models\FundingDocument::class, $record])
                <form class="upload" method="post" enctype="multipart/form-data" action="{{ route('funding.documents.store', $record) }}">
                    @csrf
                    <label for="document">Add financial evidence</label><input id="document" type="file" name="document" accept="application/pdf" required><small>PDF only. Maximum 10 MB. Lenders see these files after publication.</small><button class="button secondary">Upload privately</button>
                </form>
            @endcan
        </section>
    </div>
    @if ($owned)
        <section class="panel"><div class="panel-heading"><h2>Request actions</h2><span class="tag">Revision {{ $record->version }}</span></div>
            @if ($data['can']['update'])
                <a class="button secondary" href="{{ route('funding.edit', $record) }}">Edit draft</a>
            @endif
            @if ($data['can']['submit'])
                <form class="inline-form" method="post" action="{{ route('funding.submit', $record) }}">
                    @csrf
                    <button class="button">Submit for publication approval</button>
                </form>
            @endif
            @if ($record->pendingApproval)
                <div class="notice"><strong>{{ $record->pendingApproval->kind === 'publish' ? 'Publication approval pending' : 'Cancellation approval pending' }}</strong><p>{{ $record->pendingApproval->proposal_reason ?: 'An independent treasury approver must review the current terms and document set.' }}</p></div>
                @if ($data['can']['review'])
                    <form method="post" action="{{ route('funding.review', ['fundingRequest' => $record, 'approval' => $record->pendingApproval]) }}">
                        @csrf
                        <label for="review-reason">Review note (required when rejecting)</label><textarea id="review-reason" name="reason" rows="2" maxlength="500"></textarea>
                        <button class="button" name="decision" value="approve">Approve {{ $record->pendingApproval->kind === 'publish' ? 'publication' : 'cancellation' }}</button>
                        <button class="button secondary" name="decision" value="reject">Request changes / reject</button>
                    </form>
                @else
                    <p class="muted">The reviewer must be an independent treasury approver. Publication contributors cannot approve their own request.</p>
                @endif
            @endif
            @if ($data['can']['proposeCancellation'])
                <details class="separated"><summary>Request cancellation</summary><form method="post" action="{{ route('funding.cancel', $record) }}">
                    @csrf
                    <label for="cancel-reason">Reason for cancellation</label><textarea id="cancel-reason" name="reason" rows="2" minlength="5" maxlength="500" required></textarea><button class="button secondary">Send cancellation for approval</button>
                </form></details>
            @endif
        </section>
        <section class="panel"><h2>Recent approval history</h2>
            @forelse ($history as $approval)
                <div class="review-row"><div><strong>{{ ucfirst($approval->kind) }} · Revision {{ $approval->expected_version }}</strong><small>{{ $approval->reason ?: $approval->proposal_reason }}</small></div><span class="badge {{ $approval->status }}">{{ ucfirst($approval->status) }}</span></div>
            @empty
                <p class="empty">No approval decisions yet.</p>
            @endforelse
        </section>
    @endif
</x-layout>
