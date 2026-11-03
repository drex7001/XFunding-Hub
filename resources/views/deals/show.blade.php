@php
    $status = $record->currentStatus();
    $can = $data['can'];
    $terms = $data['terms'];
    $fee = $data['fee'];
    $isLender = $side === 'lender';
    $otherInstitution = $isLender ? 'borrower' : 'lender';
    $ownChange = $data['instructionChange'] ?? null;
    $reviewable = collect($confirmations)->first(fn (array $confirmation): bool => $confirmation['canReview']);
    $ownConfirmation = collect($confirmations)->first(fn (array $confirmation): bool => in_array($confirmation['status'], [\App\ApprovalStatus::Pending->value, \App\ApprovalStatus::Approved->value], true));
@endphp
<x-layout title="Selected deal">
    <x-page-header
        :title="$record->fundingRequest->title"
        :eyebrow="$terms['label'].' · '.$terms['borrower']['name']"
        :back="route('deals.index')"
        back-label="Selected deals"
        :status="$status"
        :meta="'Deal '.strtoupper(substr($record->id, -8))"
    >
        @if ($can['viewSettlement'])
            <a class="btn btn--secondary" href="{{ route('deals.settlement', $record) }}">View settlement details →</a>
        @endif
    </x-page-header>

    <x-stepper :steps="$progress" />

    @if ($status === \App\DealStatus::Settled)
        <x-next-step tone="done" title="Settled">
            <p>Both institutions independently confirmed. Settlement recorded <x-when :at="$record->settled_at" />.</p>
        </x-next-step>
    @elseif ($status === \App\DealStatus::Cancelled)
        <x-next-step tone="closed" title="The fee reservation is closed">
            <p>The reservation is closed. Late or unmatched funds require refund review and cannot unlock this deal.</p>
        </x-next-step>
    @elseif ($status === \App\DealStatus::ClosedUnsettled)
        <x-next-step tone="closed" title="Closed without settlement">
            <p>Closed without both approved confirmations. No transfer was reversed by this platform.</p>
        </x-next-step>
    @elseif ($ownChange)
        @if ($ownChange['canReview'])
            <x-next-step :title="'Review instruction update: version '.$ownChange['version']">
                <p>This version replaces the selected instructions once approved. Financial terms and the platform fee do not change.</p>
                <x-decision-form :action="route('deal-instructions.review', ['deal' => $record, 'change' => $ownChange['id']])" approve="Approve instruction update" reject="Reject update" id="change-reason" />
            </x-next-step>
        @else
            <x-next-step tone="waiting" title="Instruction update pending">
                <p>Version {{ $ownChange['version'] }} requires independent approval before it can replace the selected instructions.</p>
            </x-next-step>
        @endif
    @elseif ($data['instructionReviewPending'])
        <x-next-step tone="waiting" title="An instruction update is under review">
            <p>An instruction update requires independent review before payment or settlement can proceed.</p>
        </x-next-step>
    @elseif ($receivingInstructionsMissing)
        <x-next-step title="Receiving instructions required" kicker="Set up first">
            <p>The lender needs your approved receiving account. A treasury maker proposes a version here and a different approver approves it before payment or settlement can continue.</p>
            @can('viewAny', \App\Models\SettlementInstruction::class)
                <div class="btn-row"><a class="btn btn--secondary" href="{{ route('instructions.index') }}">Manage instructions</a></div>
            @endcan
        </x-next-step>
    @elseif ($status === \App\DealStatus::AwaitingFee)
        @if ($can['initiatePayment'])
            <x-next-step :title="'Pay the platform fee: '.$fee['currency'].' '.\App\Models\FundingRequest::groupDecimal($fee['amount'])">
                <p>Due by <x-when :at="$record->fee_deadline" />. Lender details unlock only after the payment provider confirms it.</p>
                <form method="post" action="{{ route('payments.store', $record) }}">
                    @csrf
                    <div class="btn-row"><button class="btn btn--primary">Request demo fee payment</button></div>
                </form>
            </x-next-step>
        @elseif ($isLender)
            <x-next-step tone="waiting" title="Waiting for the borrower to pay the platform fee">
                <p>@if ($fee) The borrower has until <x-when :at="$record->fee_deadline" />. @endif Your identity and account stay hidden until the fee is verified.</p>
            </x-next-step>
        @elseif ($pendingPayment)
            <x-next-step tone="waiting" title="Payment requested">
                <p>Awaiting independent provider confirmation. Payment reference: <span class="ref">{{ $pendingPayment->provider_reference }}</span></p>
                @if ($demoPaymentsEnabled)
                    <span class="code">Demo only. Simulate the provider from the server:<br>php artisan payments:simulate '{{ $pendingPayment->provider_reference }}'</span>
                @endif
            </x-next-step>
        @elseif ($record->hasPendingRefund())
            <x-next-step tone="waiting" title="A received payment is under review">
                <p>A received payment requires operations review before another payment attempt.</p>
            </x-next-step>
        @elseif (! $fee)
            <x-next-step tone="waiting" title="Fee quote pending">
                <p>Fee quote pending. Payment becomes available once the platform fee and deadline are confirmed.</p>
            </x-next-step>
        @else
            <x-next-step tone="waiting" title="A treasury approver pays the platform fee">
                <p>Due by <x-when :at="$record->fee_deadline" />. A borrower treasury approver can start the payment.</p>
            </x-next-step>
        @endif
    @elseif ($reviewable)
        <x-next-step title="Review your institution's settlement confirmation">
            <p>A colleague proposed this attestation. You must be a different person from the proposer.</p>
            <dl class="kv">
                @isset ($reviewable['settlementAt'])
                    <dt>Transfer completed</dt><dd><x-when :at="$reviewable['settlementAt']" /></dd>
                @endisset
                @if ($reviewable['reference'] ?? null)
                    <dt>Reference</dt><dd>{{ $reviewable['reference'] }}</dd>
                @endif
                @if ($reviewable['note'] ?? null)
                    <dt>Institution note</dt><dd>{{ $reviewable['note'] }}</dd>
                @endif
            </dl>
            <x-decision-form :action="route('confirmations.review', ['deal' => $record, 'confirmation' => $reviewable['id']])" approve="Approve settlement confirmation" reject="Reject confirmation" :id="'review-'.$reviewable['id']" />
        </x-next-step>
    @elseif ($can['proposeConfirmation'])
        <x-next-step :title="$isLender ? 'Send the principal, then confirm it' : 'Confirm the principal was received'">
            <p>
                @if ($isLender)
                    Send the principal to the borrower's receiving account through your official banking channel, then attest the transfer.
                @else
                    When the principal reaches your receiving account, attest it here.
                @endif
                A different treasury approver at your bank reviews it.
            </p>
            @if ($can['viewSettlement'])
                <p><a href="{{ route('deals.settlement', $record) }}">Open the settlement details</a></p>
            @endif
            <form method="post" action="{{ route('confirmations.store', $record) }}">
                @csrf
                <div class="form-row">
                    <div>
                        <label for="settlement_at">Transfer completed at (UTC)</label>
                        <input id="settlement_at" type="datetime-local" name="settlement_at" required step="1" value="{{ old('settlement_at', now()->format('Y-m-d\TH:i:s')) }}">
                    </div>
                    <div>
                        <label for="settlement-reference">Settlement reference <span class="muted">(optional)</span></label>
                        <input id="settlement-reference" name="reference" value="{{ old('reference') }}" maxlength="100">
                    </div>
                </div>
                <label for="settlement-note">Institution note <span class="muted">(optional)</span></label>
                <textarea id="settlement-note" name="note" maxlength="1000" rows="2">{{ old('note') }}</textarea>
                <div class="btn-row"><button class="btn btn--primary">Propose settlement confirmation</button></div>
            </form>
        </x-next-step>
    @elseif ($ownConfirmation && $ownConfirmation['status'] === \App\ApprovalStatus::Pending->value)
        <x-next-step tone="waiting" title="Your confirmation is awaiting review">
            <p>A different treasury approver at your bank must review it.</p>
        </x-next-step>
    @elseif ($status === \App\DealStatus::Disputed)
        <x-next-step tone="waiting" title="Dispute under operations review">
            <p>Dispute review is open. Approved attestations can be collected, but operations must record the resolution before settlement is finalized.</p>
        </x-next-step>
    @elseif ($ownConfirmation)
        <x-next-step tone="waiting" :title="'Waiting for the '.$otherInstitution.' to confirm'">
            <p>Your institution has confirmed. The deal settles when the {{ $otherInstitution }} institution's confirmation is approved.</p>
        </x-next-step>
    @else
        <x-next-step tone="waiting" title="Settlement is in progress">
            <p>Treasury staff at both institutions confirm the off-platform transfer.</p>
            @if ($can['viewSettlement'])
                <p>The {{ $otherInstitution }}'s identity and account are now unlocked.</p>
                <div class="btn-row"><a class="btn btn--secondary" href="{{ route('deals.settlement', $record) }}">Open the settlement details</a></div>
            @endif
        </x-next-step>
    @endif

    @if ($data['settlement']['disputeOpen'] && ($reviewable || $can['proposeConfirmation']))
        <div class="alert alert--warning">Dispute review is open. Approved attestations can be collected, but operations must record the resolution before settlement is finalized.</div>
    @endif
    @if ($data['instructionsUpdatedAt'])
        <div class="alert alert--info">Settlement instructions were independently reviewed and updated. Selected financial terms and the platform fee remain fixed.</div>
    @endif

    <div class="facts">
        <x-fact label="Selected rate" :hint="'Annual simple · '.$terms['dayCount']">{{ $terms['rate'] }}%</x-fact>
        <x-fact label="Required liquidity" :hint="$terms['tenorDays'].' days'"><x-money :currency="$terms['currency']" :amount="$terms['amount']" /></x-fact>
        <x-fact label="Expected settlement"><x-when :at="$terms['settlementDate']" date-only /></x-fact>
        <x-fact label="Offer valid until"><x-when :at="$record->offer_valid_until" /></x-fact>
    </div>

    <div class="grid-main">
        <div class="stack">
            <section class="panel">
                <h2>Settlement confirmations</h2>
                <div class="party-grid">
                    @foreach (['borrower' => 'Borrower institution', 'lender' => 'Lender institution'] as $party => $label)
                        <div class="party">
                            <span class="party__label">{{ $label }}{{ $party === $side ? ' (you)' : '' }}</span>
                            <x-status :value="$data['settlement'][$party]" />
                        </div>
                    @endforeach
                </div>
                <p class="muted party-note">These are institution attestations of an off-platform transfer. The platform does not independently verify or execute the transfer.</p>
                @foreach ($confirmations as $confirmation)
                    <div class="row">
                        <div class="row__text">
                            <strong>Your institution</strong>
                            @isset ($confirmation['reference'])
                                <small>Reference: {{ $confirmation['reference'] }}</small>
                            @endisset
                            @isset ($confirmation['note'])
                                <small>{{ $confirmation['note'] }}</small>
                            @endisset
                            @isset ($confirmation['reviewReason'])
                                <small>Review: {{ $confirmation['reviewReason'] }}</small>
                            @endisset
                        </div>
                        <x-status :value="$confirmation['status']" />
                    </div>
                @endforeach
                @unless ($can['viewSettlement'])
                    <p class="muted">Selected lender contacts and account details require a verified platform fee and authorized treasury access from eligible institutions.</p>
                @endunless
            </section>

            @if ($instructions->isNotEmpty() && ! $data['instructionReviewPending'])
                <details class="panel" @if ($receivingInstructionsMissing) open @endif>
                    <summary>Update approved settlement instructions</summary>
                    <p class="muted">A new version requires independent approval within your institution. Resolve pending payments or refunds first. After unlock, changes require an open dispute and fresh settlement confirmations.</p>
                    <form method="post" action="{{ route('deal-instructions.store', $record) }}">
                        @csrf
                        <label for="settlement_instruction_id">Approved version</label>
                        <select id="settlement_instruction_id" name="settlement_instruction_id">
                            @foreach ($instructions as $instruction)
                                <option value="{{ $instruction->id }}">Version {{ $instruction->version }} · {{ strtoupper($instruction->details['channel']) }}</option>
                            @endforeach
                        </select>
                        <div class="btn-row"><button class="btn btn--secondary">Propose instruction update</button></div>
                    </form>
                </details>
            @endif

            @if ($can['openDispute'])
                <details class="panel">
                    <summary>Report a settlement issue</summary>
                    <p class="muted">An institution approver can open a dispute for operations review.</p>
                    <form method="post" action="{{ route('disputes.store', $record) }}">
                        @csrf
                        <label for="dispute-description">Issue and evidence summary</label>
                        <textarea id="dispute-description" name="description" required minlength="10" maxlength="2000" rows="3"></textarea>
                        <div class="btn-row"><button class="btn btn--danger">Open settlement dispute</button></div>
                    </form>
                </details>
            @endif

            @foreach ($disputes as $dispute)
                <section class="panel">
                    <div class="panel__head"><h2>Settlement dispute</h2><x-status :value="$dispute['status'] === \App\DealDisputeStatus::Open->value ? 'disputed' : $dispute['status']" :label="ucfirst(str_replace('_', ' ', $dispute['status']))" /></div>
                    @isset ($dispute['description'])
                        <p class="long-text">{{ $dispute['description'] }}</p>
                    @endisset
                    @isset ($dispute['resolutionNote'])
                        <p class="long-text">{{ $dispute['resolutionNote'] }}</p>
                    @endisset
                </section>
            @endforeach
            <section class="panel">
                <h2>Timeline</h2>
                @if ($events->isNotEmpty())
                    <h3 class="subhead">Settlement activity</h3>
                    <ol class="timeline">
                        @foreach ($events as $event)
                            <li><strong>{{ \App\Actions\RecordDealActivity::label($event->kind, $event->side) }}</strong><small><x-when :at="$event->created_at" /></small></li>
                        @endforeach
                    </ol>
                    {{ $events->links() }}
                @endif
                <h3 class="subhead">Selection and fee milestones</h3>
                <ol class="timeline">
                    @foreach (collect($milestones)->reverse() as $milestone)
                        <li><strong>{{ $milestone['message'] }}</strong><small><x-when :at="$milestone['at']" /></small></li>
                    @endforeach
                </ol>
            </section>
        </div>

        <div class="stack">
            <section class="panel">
                <h2>Selected terms</h2>
                <dl class="kv">
                    <dt>Collateral</dt><dd>{{ ucfirst(str_replace('_', ' ', $terms['collateral'])) }}</dd>
                    <dt>Interest payment</dt><dd>{{ ucfirst(str_replace('_', ' ', $terms['interestPayment'])) }}</dd>
                    <dt>Rate basis</dt><dd>Annual simple · {{ $terms['dayCount'] }}</dd>
                </dl>
                @can('view', $record->fundingRequest)
                    <p class="muted"><a href="{{ route('funding.show', $data['fundingRequestId']) }}">View funding request</a></p>
                @endcan
            </section>

            <section class="panel">
                <div class="panel__head"><h2>Platform fee</h2><span class="tag">Demo · No real money</span></div>
                @if ($fee)
                    <dl class="kv">
                        <dt>Total</dt><dd><strong><x-money :currency="$fee['currency']" :amount="$fee['amount']" /></strong></dd>
                        <dt>Service fee</dt><dd>{{ $fee['basisPoints'] }} bps of principal</dd>
                        <dt>Demo tax</dt><dd>{{ $fee['taxAmount'] }}</dd>
                        <dt>Payment deadline</dt><dd><x-when :at="$record->fee_deadline" /></dd>
                    </dl>
                    @if ($data['identityUnlocked'])
                        <p class="muted">The platform fee has been verified.</p>
                    @endif
                @else
                    <p class="empty-line">Fee quote pending.</p>
                @endif
                @foreach ($payments as $payment)
                    <div class="row">
                        <div class="row__text">
                            <strong><x-money :currency="$payment->currency" :amount="$payment->amount" /></strong>
                            <small class="ref">{{ $payment->provider_reference }}</small>
                            @if ($payment->failure_reason)
                                <small>{{ ucfirst(str_replace('_', ' ', $payment->failure_reason)) }}</small>
                            @endif
                        </div>
                        <x-status :value="$payment->status->value" :label="$payment->status->label()" />
                    </div>
                @endforeach
            </section>
        </div>
    </div>
</x-layout>
