<x-layout title="Selected deal">
    <a class="small-link" href="{{ route('deals.index') }}">← Selected deals</a>
    <div class="page-heading"><div><span class="eyebrow">SELECTED TERMS</span><h1>{{ $data['terms']['label'] }} {{ match ($data['status']) { 'settled' => 'settled', 'disputed' => 'under review', 'cancelled', 'closed_unsettled' => 'closed', default => 'selected' } }}.</h1><p class="muted">{{ $data['terms']['borrower']['name'] }} · Deal {{ $data['id'] }}</p></div><span class="badge {{ $data['status'] }}">{{ ucwords(str_replace('_', ' ', $data['status'])) }}</span></div>
    <div class="summary-grid"><div class="summary"><span class="eyebrow">SELECTED RATE</span><strong>{{ $data['terms']['rate'] }}%</strong><small>Annual simple rate · {{ $data['terms']['dayCount'] }}</small></div><div class="summary"><span class="eyebrow">REQUIRED LIQUIDITY</span><strong>{{ $data['terms']['currency'] }} {{ $data['terms']['amount'] }}</strong><small>{{ $data['terms']['tenorDays'] }} days</small></div><div class="summary"><span class="eyebrow">OFFER VALID UNTIL</span><strong>{{ $record->offer_valid_until->format('d M Y, H:i') }}</strong><small>UTC</small></div></div>
    @if ($data['instructionsUpdatedAt'])
        <div class="notice">Settlement instructions were independently reviewed and updated. Selected financial terms and the platform fee remain fixed.</div>
    @endif
    <div class="content-grid"><section class="panel"><h2>Platform fee</h2>
        @if ($data['fee'])
            <span class="tag">DEMO PAYMENTS · No real money collected</span><strong class="request-amount">{{ $data['fee']['currency'] }} {{ $data['fee']['amount'] }}</strong><dl><dt>Service fee</dt><dd>{{ $data['fee']['basisPoints'] }} basis points of the principal · {{ $data['fee']['baseAmount'] }}</dd><dt>Demo tax</dt><dd>{{ $data['fee']['taxAmount'] }}</dd><dt>Payment deadline (UTC)</dt><dd>{{ $record->fee_deadline->format('d M Y, H:i') }}</dd></dl>
        @else
            <p class="muted">Fee quote pending. Payment becomes available once the platform fee and deadline are confirmed.</p>
        @endif
        @if ($data['status'] === 'cancelled')
            <div class="notice">The reservation is closed. Late or unmatched funds require refund review and cannot unlock this deal.</div>
        @elseif ($data['identityUnlocked'])
            <div class="notice">The platform fee has been verified.</div>
        @elseif ($data['instructionReviewPending'])
            <p class="muted">An instruction update requires independent lender review before payment can proceed.</p>
        @elseif ($record->hasPendingRefund())
            <p class="muted">A received payment requires operations review before another payment attempt.</p>
        @elseif ($record->hasPendingPayment())
            <p class="muted">Payment requested. Awaiting independent provider confirmation.</p>
        @endif
        @if ($data['can']['initiatePayment'])
            <form method="post" action="{{ route('payments.store', $record) }}">
                @csrf
                <button class="button">Request demo fee payment</button>
            </form><small>The borrower approver can initiate payment. Provider confirmation is required to unlock details.</small>
        @endif
    </section><section class="panel"><h2>{{ $data['can']['viewSettlement'] ? 'Settlement details available.' : 'Settlement details protected.' }}</h2>
        @if ($data['can']['viewSettlement'])
            <p>The selected lender’s independently approved details are ready for your institution’s settlement process.</p><a class="button" href="{{ route('deals.settlement', $record) }}">View settlement details →</a>
        @else
            <p class="muted">Selected lender contacts and account details require a verified platform fee and authorized treasury access from eligible institutions.</p>
        @endif
        <dl><dt>Collateral</dt><dd>{{ ucwords(str_replace('_', ' ', $data['terms']['collateral'])) }}</dd><dt>Interest payment</dt><dd>{{ ucwords(str_replace('_', ' ', $data['terms']['interestPayment'])) }}</dd><dt>Expected settlement</dt><dd>{{ $data['terms']['settlementDate'] }}</dd></dl><a class="small-link" href="{{ route('funding.show', $data['fundingRequestId']) }}">View funding request →</a>
    </section></div>
    @if ($payments->isNotEmpty())
        <section class="panel"><h2>Your fee payment history</h2>
            @foreach ($payments as $payment)
                <div class="review-row"><div><strong>{{ $payment->currency }} {{ $payment->amount }}</strong><small>{{ $payment->provider_reference }}</small><small>{{ $payment->failure_reason ? ucwords(str_replace('_', ' ', $payment->failure_reason)) : 'Provider confirmation tracked independently.' }}</small></div><span class="badge {{ $payment->status->value }}">{{ $payment->status->label() }}</span></div>
            @endforeach
        </section>
    @endif
    @isset ($data['instructionChange'])
        <section class="panel"><h2>Instruction update pending</h2><p class="muted">Version {{ $data['instructionChange']['version'] }} requires independent approval before it can replace the selected instructions.</p>
            @if ($data['instructionChange']['canReview'])
                <form method="post" action="{{ route('deal-instructions.review', ['deal' => $record, 'change' => $data['instructionChange']['id']]) }}">
                    @csrf
                    <label for="change-reason">Review note (required when rejecting)</label><textarea id="change-reason" name="reason" rows="2" maxlength="500"></textarea><button class="button" name="decision" value="approve">Approve instruction update</button><button class="button secondary" name="decision" value="reject">Reject update</button>
                </form>
            @endif
        </section>
    @endisset
    @if ($instructions->isNotEmpty() && !$data['instructionReviewPending'])
        <section class="panel"><h2>Update approved settlement instructions</h2><p class="muted">A new version requires independent lender approval. Resolve pending payments or refunds first. After unlock, changes require an open dispute and fresh settlement confirmations.</p><form method="post" action="{{ route('deal-instructions.store', $record) }}">
            @csrf
            <label for="settlement_instruction_id">Approved version</label><select id="settlement_instruction_id" name="settlement_instruction_id">
                @foreach ($instructions as $instruction)
                    <option value="{{ $instruction->id }}">Version {{ $instruction->version }} · {{ strtoupper($instruction->details['channel']) }}</option>
                @endforeach
            </select><button class="button secondary">Propose instruction update</button>
        </form></section>
    @endif
    <section class="panel"><h2>Settlement confirmations</h2><p class="muted">These are institution attestations of an off-platform transfer. The platform does not independently verify or execute the transfer.</p>
        <div class="summary-grid">
            @foreach (['borrower' => 'Borrower institution', 'lender' => 'Lender institution'] as $side => $label)
                <div class="summary"><span class="eyebrow">{{ $label }}</span><strong>{{ ucwords(str_replace('_', ' ', $data['settlement'][$side])) }}</strong></div>
            @endforeach
        </div>
        @if ($data['settlement']['disputeOpen'])
            <div class="notice">Dispute review is open. Approved attestations can be collected, but operations must record the resolution before settlement is finalized.</div>
        @elseif ($data['status'] === 'settled')
            <div class="notice">Both institutions independently confirmed. Settlement recorded {{ $record->settled_at->format('d M Y, H:i') }} UTC.</div>
        @elseif ($data['status'] === 'closed_unsettled')
            <div class="notice">Closed without both approved confirmations. No transfer was reversed by this platform.</div>
        @else
            <p class="muted">One approved confirmation keeps settlement pending. Both institutions must confirm against the same approved instruction version.</p>
        @endif
        @if ($data['can']['proposeConfirmation'])
            <form method="post" action="{{ route('confirmations.store', $record) }}">
                @csrf
                <label for="settlement_at">Transfer completed at (UTC)</label><input id="settlement_at" type="datetime-local" name="settlement_at" required step="1" value="{{ now()->format('Y-m-d\\TH:i:s') }}">
                <label for="settlement-reference">Settlement reference (optional)</label><input id="settlement-reference" name="reference" maxlength="100">
                <label for="settlement-note">Institution note (optional)</label><textarea id="settlement-note" name="note" maxlength="1000" rows="2"></textarea>
                <button class="button">Propose settlement confirmation</button>
            </form>
        @endif
        @foreach ($confirmations as $confirmation)
            <div class="review-row"><div><strong>Your institution · {{ ucfirst($confirmation['status']) }}</strong>
                @isset ($confirmation['reference'])<small>Reference: {{ $confirmation['reference'] }}</small>@endisset
                @isset ($confirmation['note'])<small>{{ $confirmation['note'] }}</small>@endisset
                @isset ($confirmation['reviewReason'])<small>Review: {{ $confirmation['reviewReason'] }}</small>@endisset
            </div>
            @if ($confirmation['canReview'])
                <form method="post" action="{{ route('confirmations.review', ['deal' => $record, 'confirmation' => $confirmation['id']]) }}">
                    @csrf
                    <label for="review-{{ $confirmation['id'] }}">Review reason (required if rejecting)</label><input id="review-{{ $confirmation['id'] }}" name="reason" maxlength="500">
                    <button class="button" name="decision" value="approve">Approve settlement confirmation</button><button class="button secondary" name="decision" value="reject">Reject confirmation</button>
                </form>
            @endif</div>
        @endforeach
    </section>
    @if ($data['can']['openDispute'])
        <section class="panel"><h2>Report a settlement issue</h2><p class="muted">An institution approver can open a dispute for operations review.</p><form method="post" action="{{ route('disputes.store', $record) }}">
            @csrf
            <label for="dispute-description">Issue and evidence summary</label><textarea id="dispute-description" name="description" required minlength="10" maxlength="2000" rows="3"></textarea><button class="button secondary">Open settlement dispute</button>
        </form></section>
    @endif
    @foreach ($disputes as $dispute)
        <section class="panel"><h2>Dispute · {{ ucwords(str_replace('_', ' ', $dispute['status'])) }}</h2>
            @isset ($dispute['description'])<p>{{ $dispute['description'] }}</p>@endisset
            @isset ($dispute['resolutionNote'])<p>{{ $dispute['resolutionNote'] }}</p>@endisset
        </section>
    @endforeach
    <section class="panel"><h2>Deal timeline</h2><h3>Selection and fee milestones</h3>
        @foreach ($milestones as $milestone)
            <div class="review-row"><strong>{{ $milestone['message'] }}</strong><small>{{ \Carbon\CarbonImmutable::parse($milestone['at'])->format('d M Y, H:i:s') }} UTC</small></div>
        @endforeach
        <h3>Settlement activity · latest first</h3>
        @foreach ($events as $event)
            <div class="review-row"><strong>{{ \App\Actions\RecordDealActivity::label($event->kind, $event->side) }}</strong><small>{{ $event->created_at->format('d M Y, H:i:s') }} UTC</small></div>
        @endforeach
        {{ $events->links() }}
    </section>
</x-layout>
