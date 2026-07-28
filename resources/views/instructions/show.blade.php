<x-layout title="Instruction review">
    <a class="small-link" href="{{ route('instructions.index') }}">← Instruction versions</a>
    <div class="page-heading"><div><span class="eyebrow">PRIVATE LENDER INSTRUCTIONS</span><h1>Version {{ $record->version }}.</h1><p class="muted">{{ $data['bank']['name'] }} · {{ strtoupper($data['details']['channel']) }}</p></div><span class="badge {{ $record->status }}">{{ ucfirst($record->status) }}</span></div>
    <div class="content-grid"><section class="panel"><h2>Settlement account</h2><dl>
        <dt>Account holder</dt><dd>{{ $data['details']['account_name'] }}</dd><dt>Account number / IBAN</dt><dd>{{ $data['details']['account_number'] }}</dd>
        <dt>{{ $data['details']['channel'] === 'swift' ? 'SWIFT BIC' : 'RTGS routing code' }}</dt><dd>{{ $data['details']['bic'] ?? $data['details']['rtgs_code'] }}</dd>
        <dt>Treasury contact</dt><dd>{{ $data['details']['contact_name'] }}</dd><dt>Contact email</dt><dd>{{ $data['details']['contact_email'] }}</dd>
    </dl><p class="muted">This version is immutable. Propose a new version when details change.</p></section>
    <section class="panel"><h2>Independent review</h2>
        @if ($data['canReview'])
            <form method="post" action="{{ route('instructions.review', $record) }}">
                @csrf
                <label for="reason">Review note (required when rejecting)</label><textarea id="reason" name="reason" rows="3" maxlength="500"></textarea>
                <button class="button" name="decision" value="approve">Approve instructions</button><button class="button secondary" name="decision" value="reject">Reject version</button>
            </form>
        @else
            <p class="muted">{{ $record->status === 'pending' ? 'A different lender treasury approver must review this version.' : 'The review for this version is complete.' }}</p>
        @endif
        @if ($data['reason'])
            <p class="long-text">{{ $data['reason'] }}</p>
        @endif
    </section></div>
</x-layout>
