<x-layout title="Private fee receipt">
    <x-page-header
        :title="$data['currency'].' '.\App\Models\FundingRequest::groupDecimal($data['amount'])"
        eyebrow="Audited operations review"
        :back="route('payments.operations')"
        back-label="Payment exceptions"
        :status="$data['status']"
        :meta="'Demo provider receipt '.strtoupper(substr($data['id'], -8))"
    />

    <div class="grid-even">
        <section class="panel">
            <h2>Reconciliation details</h2>
            <dl class="kv">
                <dt>Payment reference</dt><dd class="ref">{{ $data['reference'] }}</dd>
                <dt>Provider charge</dt><dd class="ref">{{ $data['chargeReference'] }}</dd>
                <dt>Expected fee</dt><dd>{{ $data['expectedCurrency'] }} {{ $data['expectedAmount'] ?? 'Unmatched payment' }}</dd>
                <dt>Review reason</dt><dd>{{ ucfirst(str_replace('_', ' ', (string) $data['reason'])) }}</dd>
                <dt>Received at</dt><dd><x-when :at="$data['receivedAt']" /></dd>
            </dl>
        </section>
        <section class="panel">
            <h2>Record demo refund confirmation</h2>
            @if ($data['status'] === 'refund_review')
                <p class="muted">Record the confirmation after independent reconciliation. This action records an attestation and does not transfer money.</p>
                <form method="post" action="{{ route('payments.receipts.refund', $record) }}">
                    @csrf
                    <label for="refund_reference">Refund confirmation reference</label>
                    <input id="refund_reference" name="refund_reference" minlength="5" maxlength="100" required>
                    <label for="refund-reason">Reconciliation and refund reason</label>
                    <textarea id="refund-reason" name="reason" rows="3" minlength="8" maxlength="500" required></textarea>
                    <div class="btn-row"><button class="btn btn--primary">Record refund confirmation</button></div>
                </form>
            @else
                <p>{{ $data['refundReference'] }}</p>
                <p class="long-text">{{ $data['reviewReason'] }}</p>
            @endif
        </section>
    </div>
</x-layout>
