<x-layout title="Settlement details">
    <x-page-header
        title="Settlement details"
        :back="route('deals.show', $record)"
        back-label="Selected deal"
        status="verified"
        status-label="Fee verified"
        meta="Independently approved instructions from both institutions."
    />

    <div class="alert alert--info">The lender sends principal to the borrower's receiving account through official banking channels. This platform does not execute or hold the interbank transfer.</div>

    <div class="grid-even section-gap">
        @foreach ([['title' => 'Borrower receiving account', 'party' => $data['borrower'], 'details' => $data['borrowerInstructions']], ['title' => 'Lender settlement account', 'party' => $data['lender'], 'details' => $data['instructions']]] as $side)
            <section class="panel">
                <h2>{{ $side['title'] }}</h2>
                @if ($side['details'])
                    <p class="muted">{{ $side['party']['name'] }} · {{ $side['party']['countryCode'] }} · {{ $side['party']['registrationNumber'] }}</p>
                    <dl class="kv">
                        <dt>Channel / version</dt><dd>{{ strtoupper($side['details']['channel']) }} · Version {{ $side['details']['version'] }}</dd>
                        <dt>Account holder</dt><dd>{{ $side['details']['accountName'] }}</dd>
                        <dt>Account number / IBAN</dt><dd>{{ $side['details']['accountNumber'] }}</dd>
                        <dt>{{ $side['details']['channel'] === 'swift' ? 'SWIFT BIC' : 'RTGS routing code' }}</dt><dd>{{ $side['details']['bic'] ?? $side['details']['rtgsCode'] }}</dd>
                        <dt>Treasury contact</dt><dd>{{ $side['details']['contactName'] }}</dd>
                        <dt>Contact email</dt><dd>{{ $side['details']['contactEmail'] }}</dd>
                    </dl>
                @else
                    <p class="empty-line">The borrower has not recorded approved receiving instructions for this deal yet.</p>
                @endif
            </section>
        @endforeach
    </div>
    <p class="muted">Your access to these details is recorded in the institution audit history.</p>
</x-layout>
