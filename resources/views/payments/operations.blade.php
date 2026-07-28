<x-layout title="Fee payment review">
    <div class="page-heading"><div><span class="eyebrow">PLATFORM OPERATIONS</span><h1>Review payment exceptions.</h1><p class="muted">Inspect received fees and record independent demo refund confirmations.</p></div></div>
    <div class="notice">This workspace cannot mark a fee paid or unlock lender identity. Demo refund records do not execute bank transfers.</div>
    <section class="panel"><h2>Receipts awaiting refund review</h2>
        @forelse ($receipts as $receipt)
            <div class="review-row"><div><strong>{{ $receipt->currency }} {{ $receipt->amount }}</strong><small>{{ ucwords(str_replace('_', ' ', $receipt->reason)) }} · {{ $receipt->received_at->format('d M Y, H:i') }} UTC</small><small>{{ $receipt->fee_payment_id ? 'Matched payment attempt' : 'Unmatched provider receipt' }}</small></div><form method="post" action="{{ route('payments.receipts.review', $receipt) }}">
                @csrf
                <label for="reason-{{ $receipt->id }}">Reason for privileged review</label><input id="reason-{{ $receipt->id }}" name="reason" minlength="8" maxlength="500" required><button class="button secondary">Review receipt</button>
            </form></div>
        @empty
            <p class="empty">No fee receipts require refund review.</p>
        @endforelse
        {{ $receipts->links() }}
    </section>
    <section class="panel"><h2>Unmatched or conflicting notifications</h2>
        @forelse ($notifications as $notification)
            <div class="review-row"><div><strong>{{ ucwords(str_replace('_', ' ', $notification->outcome)) }}</strong><small>Event {{ $notification->event_reference }}</small><small>{{ $notification->created_at->format('d M Y, H:i') }} UTC</small></div><span class="badge pending">Reconcile provider records</span></div>
        @empty
            <p class="empty">No unmatched or conflicting notification deliveries.</p>
        @endforelse
    </section>
</x-layout>
