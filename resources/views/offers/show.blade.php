@php
    $status = $record->currentStatus();
    $can = $data['can'];
    $deal = $record->fundingRequest->deal;
@endphp
<x-layout title="Offer">
    <x-page-header
        :title="$data['title']"
        :eyebrow="$data['borrowerName']"
        :back="route('offers.index')"
        back-label="Your offers"
        :status="$data['status']"
        :status-label="$status->label()"
        :meta="'Your offer · Revision '.$data['version']"
    >
        @if ($can['update'])
            <a class="btn btn--secondary" href="{{ route('offers.edit', $record) }}">Edit offer</a>
        @endif
    </x-page-header>

    <x-stepper :steps="$progress" />

    @if ($can['review'])
        <x-next-step title="Approve this offer">
            <p>Check the rate, conditions and instruction version below, then record your independent decision.</p>
            <x-decision-form :action="route('offers.review', ['offer' => $record, 'approval' => $data['pendingApproval']['id']])" approve="Approve offer" reject="Request changes / reject" id="reason" />
        </x-next-step>
    @elseif ($data['pendingApproval'])
        <x-next-step tone="waiting" title="Independent approval is pending">
            <p>A different lender treasury approver who did not work on this offer must approve these terms.</p>
        </x-next-step>
    @elseif ($can['submit'])
        <x-next-step title="Send this offer for approval">
            <p>A different lender treasury approver reviews it. It becomes visible to the borrower once approved.</p>
            <form method="post" action="{{ route('offers.submit', $record) }}">
                @csrf
                <div class="btn-row"><button class="btn btn--primary">Submit offer for approval</button></div>
            </form>
        </x-next-step>
    @elseif ($status === \App\OfferStatus::Active)
        <x-next-step tone="waiting" title="Your offer is live">
            <p>The borrower sees it as an anonymous offer. To change the terms, withdraw it and obtain approval for a replacement.</p>
        </x-next-step>
    @elseif ($status === \App\OfferStatus::Selected && $deal)
        <x-next-step title="Your offer was selected">
            <p>This offer is reserved. Its terms and instructions cannot be changed.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('deals.show', $deal) }}">View selected deal <x-icon name="arrow-right" /></a></div>
        </x-next-step>
    @elseif (in_array($status, [\App\OfferStatus::Withdrawn, \App\OfferStatus::Expired, \App\OfferStatus::Unsuccessful], true))
        <x-next-step tone="closed" :title="'This offer is '.strtolower($status->label())">
            <p>It is no longer available to the borrower.</p>
        </x-next-step>
    @endif

    <div class="facts">
        <x-fact label="Annual simple rate" :hint="$data['dayCount']">{{ $data['rate'] }}%</x-fact>
        <x-fact label="Full request coverage" :hint="$data['tenorDays'].' days'"><x-money :currency="$data['currency']" :amount="$data['amount']" /></x-fact>
        <x-fact label="Offer valid until"><x-when :at="$record->valid_until" /></x-fact>
    </div>

    <div class="grid-even">
        <section class="panel">
            <h2>Offer conditions</h2>
            <dl class="kv">
                <dt>Collateral</dt><dd>{{ ucfirst(str_replace('_', ' ', $data['collateral'])) }}</dd>
                <dt>Interest payment</dt><dd>{{ ucfirst(str_replace('_', ' ', $data['interestPayment'])) }}</dd>
            </dl>
            @isset ($data['instructionId'])
                <p class="muted"><a href="{{ route('instructions.show', $data['instructionId']) }}">Review approved instructions · Version {{ $data['instructionVersion'] }}</a></p>
            @endisset
            <p class="muted">Borrowers receive an anonymous structured comparison. Competitor offers are private.</p>
            @if ($can['withdraw'])
                <details class="more">
                    <summary>Withdraw this offer</summary>
                    <form method="post" action="{{ route('offers.withdraw', $record) }}">
                        @csrf
                        <label for="withdraw-reason">Reason</label>
                        <textarea id="withdraw-reason" name="reason" minlength="5" maxlength="500" rows="2" required></textarea>
                        <div class="btn-row"><button class="btn btn--danger">Withdraw offer</button></div>
                    </form>
                </details>
            @endif
        </section>
        <section class="panel">
            <h2>Approval history</h2>
            @forelse ($history as $approval)
                <div class="row">
                    <div class="row__text">
                        <strong>Revision {{ $approval->expected_version }}</strong>
                        @if ($approval->reason)
                            <small>{{ $approval->reason }}</small>
                        @endif
                    </div>
                    <x-status :value="$approval->status" />
                </div>
            @empty
                <p class="empty-line">No approval proposals yet.</p>
            @endforelse
        </section>
    </div>
</x-layout>
