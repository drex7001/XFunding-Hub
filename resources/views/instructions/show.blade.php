<x-layout title="Settlement instructions">
    <x-page-header
        :title="'Version '.$record->version"
        :eyebrow="$data['bank']['name']"
        :back="route('instructions.index')"
        back-label="Settlement instructions"
        :status="$record->status"
        :meta="strtoupper($data['details']['channel']).' · Private banking instructions'"
    />

    @if ($data['canReview'])
        <x-next-step title="Approve these instructions">
            <p>Check the account below, then record your independent decision.</p>
            <x-decision-form :action="route('instructions.review', $record)" approve="Approve instructions" reject="Reject version" id="reason" />
        </x-next-step>
    @elseif ($record->status === 'pending')
        <x-next-step tone="waiting" title="Independent approval is pending">
            <p>A different treasury approver must review this version.</p>
        </x-next-step>
    @endif

    <div class="grid-even">
        <section class="panel">
            <h2>Settlement account</h2>
            <dl class="kv">
                <dt>Account holder</dt><dd>{{ $data['details']['account_name'] }}</dd>
                <dt>Account number / IBAN</dt><dd>{{ $data['details']['account_number'] }}</dd>
                <dt>{{ $data['details']['channel'] === 'swift' ? 'SWIFT BIC' : 'RTGS routing code' }}</dt><dd>{{ $data['details']['bic'] ?? $data['details']['rtgs_code'] }}</dd>
                <dt>Treasury contact</dt><dd>{{ $data['details']['contact_name'] }}</dd>
                <dt>Contact email</dt><dd>{{ $data['details']['contact_email'] }}</dd>
            </dl>
            <p class="muted">This version is immutable. Propose a new version when details change.</p>
        </section>
        <section class="panel">
            <h2>Review</h2>
            @if ($record->status === 'pending')
                <p class="empty-line">The review for this version has not been recorded yet.</p>
            @else
                <p class="muted">The review for this version is complete.</p>
            @endif
            @if ($data['reason'])
                <p class="long-text">{{ $data['reason'] }}</p>
            @endif
            @if ($data['retiredAt'])
                <p class="muted">Retired <x-when :at="$data['retiredAt']" />.</p>
            @endif
            @if ($data['canRetire'])
                <details class="more">
                    <summary>Retire this version</summary>
                    <form method="post" action="{{ route('instructions.retire', $record) }}">
                        @csrf
                        <label for="retirement-reason">Reason to retire this version</label>
                        <textarea id="retirement-reason" name="reason" rows="2" minlength="5" maxlength="500" required></textarea>
                        <span class="hint">A retired version cannot be attached to new work. A version used by an active offer, pending selection or open deal cannot be retired.</span>
                        <div class="btn-row"><button class="btn btn--danger">Retire version</button></div>
                    </form>
                </details>
            @endif
        </section>
    </div>
</x-layout>
