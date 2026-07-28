<x-layout title="Private fee receipt">
    <a class="small-link" href="{{ route('payments.operations') }}">← Payment exceptions</a>
    <div class="page-heading"><div><span class="eyebrow">AUDITED OPERATIONS REVIEW</span><h1>{{ $data['currency'] }} {{ $data['amount'] }}</h1><p class="muted">Demo provider receipt {{ $data['id'] }}</p></div><span class="badge {{ $data['status'] }}">{{ ucwords(str_replace('_', ' ', $data['status'])) }}</span></div>
    <div class="content-grid"><section class="panel"><h2>Reconciliation details</h2><dl><dt>Payment reference</dt><dd>{{ $data['reference'] }}</dd><dt>Provider charge</dt><dd>{{ $data['chargeReference'] }}</dd><dt>Expected fee</dt><dd>{{ $data['expectedCurrency'] }} {{ $data['expectedAmount'] ?? 'Unmatched payment' }}</dd><dt>Review reason</dt><dd>{{ ucwords(str_replace('_', ' ', $data['reason'])) }}</dd><dt>Received at (UTC)</dt><dd>{{ \Carbon\CarbonImmutable::parse($data['receivedAt'])->format('d M Y, H:i') }}</dd></dl></section><section class="panel"><h2>Record demo refund confirmation</h2>
        @if ($data['status'] === 'refund_review')
            <p class="muted">Record the confirmation after independent reconciliation. This action records an attestation and does not transfer money.</p><form method="post" action="{{ route('payments.receipts.refund', $record) }}">
                @csrf
                <label for="refund_reference">Refund confirmation reference</label><input id="refund_reference" name="refund_reference" minlength="5" maxlength="100" required><label for="refund-reason">Reconciliation and refund reason</label><textarea id="refund-reason" name="reason" rows="3" minlength="8" maxlength="500" required></textarea><button class="button">Record refund confirmation</button>
            </form>
        @else
            <p>{{ $data['refundReference'] }}</p><p class="long-text">{{ $data['reviewReason'] }}</p>
        @endif
    </section></div>
</x-layout>
