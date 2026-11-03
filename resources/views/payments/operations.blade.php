<x-layout title="Payment exceptions">
    <x-page-header title="Payment exceptions" meta="Inspect received fees and record independent demo refund confirmations." />

    <div class="alert alert--info">This workspace cannot mark a fee paid or unlock lender identity. Demo refund records do not execute bank transfers.</div>

    <div class="stack">
        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush"><h2>Receipts awaiting refund review</h2></div>
            @forelse ($receipts as $receipt)
                <div class="row row--flush">
                    <div class="row__text">
                        <strong><x-money :currency="$receipt->currency" :amount="$receipt->amount" /></strong>
                        <small>{{ ucfirst(str_replace('_', ' ', (string) $receipt->reason)) }} · <x-when :at="$receipt->received_at" /></small>
                        <small>{{ $receipt->fee_payment_id ? 'Matched payment attempt' : 'Unmatched provider receipt' }}</small>
                    </div>
                    <form class="row-form" method="post" action="{{ route('payments.receipts.review', $receipt) }}">
                        @csrf
                        <div>
                            <label for="reason-{{ $receipt->id }}">Reason for privileged review</label>
                            <input id="reason-{{ $receipt->id }}" name="reason" minlength="8" maxlength="500" required>
                        </div>
                        <button class="btn btn--secondary">Review receipt</button>
                    </form>
                </div>
            @empty
                <x-empty-state title="No fee receipts require refund review" />
            @endforelse
            {{ $receipts->links() }}
        </section>

        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush"><h2>Unmatched or conflicting notifications</h2></div>
            @forelse ($notifications as $notification)
                <div class="row row--flush">
                    <div class="row__text">
                        <strong>{{ ucfirst(str_replace('_', ' ', $notification->outcome)) }}</strong>
                        <small>Event <span class="ref">{{ $notification->event_reference }}</span></small>
                        <small><x-when :at="$notification->created_at" /></small>
                    </div>
                    <x-status value="pending" label="Reconcile provider records" />
                </div>
            @empty
                <x-empty-state title="No unmatched or conflicting deliveries" />
            @endforelse
        </section>
    </div>
</x-layout>
