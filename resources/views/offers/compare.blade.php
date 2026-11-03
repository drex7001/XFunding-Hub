@php
    $hasInstructions = $data['receivingInstructions'] !== [];
    $canSelect = $data['canPropose'] && $hasInstructions;
    $lowestRate = count($data['offers']) > 1 ? collect($data['offers'])->min(fn (array $offer): float => (float) $offer['rate']) : null;
    $cutoff = now()->addMinutes(config('marketplace.minimum_fee_window_minutes'));
@endphp
<x-layout title="Compare offers">
    <x-page-header title="Compare offers" :back="route('funding.show', $record)" back-label="Funding request" />

    <div class="facts">
        <x-fact label="Request">{{ $record->title }}</x-fact>
        <x-fact label="Required liquidity" :hint="$record->tenor_days.' days · '.$record->day_count"><x-money :currency="$record->currency" :amount="$record->amount" /></x-fact>
        <x-fact label="Bidding deadline"><x-when :at="$record->bidding_deadline" /></x-fact>
    </div>

    @if ($data['deal'])
        <x-next-step :title="$data['deal']['terms']['label'].' selected'">
            <p>Your selected terms are fixed. Other offers are closed.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('deals.show', $data['deal']['id']) }}">View selected deal <x-icon name="arrow-right" /></a></div>
        </x-next-step>
    @endif

    @if ($data['pendingSelection'])
        @php($pending = $data['pendingSelection'])
        <x-next-step :tone="$pending['canReview'] ? 'action' : 'waiting'" :title="'Selection approval pending: '.$pending['offer']['label']">
            <p>
                {{ $pending['offer']['rate'] }}% · {{ ucfirst(str_replace('_', ' ', $pending['offer']['collateral'])) }} · Interest {{ str_replace('_', ' ', $pending['offer']['interestPayment']) }}
                · Valid until <x-when :at="$pending['offer']['validUntil']" />
            </p>
            @if ($pending['receivingInstructionVersion'])
                <p>Receiving instructions: version {{ $pending['receivingInstructionVersion'] }}. They are disclosed to the selected lender only after the platform fee is verified.</p>
            @endif
            @if ($pending['canReview'])
                <x-decision-form :action="route('selection.review', ['fundingRequest' => $record, 'selection' => $pending['id']])" approve="Approve selection" reject="Reject selection" id="reason" />
            @else
                <p class="muted">A different borrower treasury approver must review this selection.</p>
            @endif
        </x-next-step>
    @endif

    @if ($data['canPropose'] && ! $hasInstructions)
        <x-next-step title="Approve your receiving account first" kicker="Set up first">
            <p>The selected lender needs your account to send principal. A treasury maker proposes the instructions and a different approver approves them before a selection can be proposed.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('instructions.index') }}">Manage instructions</a></div>
        </x-next-step>
    @endif

    <section class="panel panel--flush">
        @if ($data['offers'] === [])
            @unless ($data['deal'])
                <x-empty-state icon="tag" title="No available offers" text="Independently approved, valid offers appear here while your bidding window is open." />
            @else
                <x-empty-state icon="check" title="Other offers are closed" text="This request has a selected deal." />
            @endunless
        @else
            <div class="panel__head panel__head--flush">
                <h2>{{ count($data['offers']) }} {{ Str::plural('offer', count($data['offers'])) }} from anonymous lenders</h2>
                <span class="muted"><x-icon name="lock" /> Identities stay locked until the fee is verified</span>
            </div>
            @if ($canSelect)
                <form method="post" action="{{ route('selection.store', $record) }}">
                    @csrf
                    <div class="panel__body">
                        <label for="receiving-account">Your receiving account</label>
                        <select id="receiving-account" name="settlement_instruction_id" required>
                            @foreach ($data['receivingInstructions'] as $instruction)
                                <option value="{{ $instruction['id'] }}" @selected(old('settlement_instruction_id') === $instruction['id'])>Version {{ $instruction['version'] }} · {{ strtoupper($instruction['channel']) }}</option>
                            @endforeach
                        </select>
                        <span class="hint">The selected lender receives this account only after the platform fee is verified.</span>
                    </div>
            @endif
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Offer</th>
                            <th>Annual simple rate</th>
                            <th>Collateral</th>
                            <th>Interest payment</th>
                            <th>Valid until</th>
                            @if ($canSelect)
                                <th><span class="sr-only">Select</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['offers'] as $offer)
                            <tr>
                                <td class="primary">
                                    <span class="cell-title">{{ $offer['label'] }}</span>
                                    @if ($lowestRate !== null && (float) $offer['rate'] === $lowestRate)
                                        <span class="tag tag--brand">Lowest rate</span>
                                    @endif
                                </td>
                                <td data-label="Annual simple rate"><span class="rate">{{ $offer['rate'] }}%</span></td>
                                <td data-label="Collateral">{{ ucfirst(str_replace('_', ' ', $offer['collateral'])) }}</td>
                                <td data-label="Interest payment">{{ ucfirst(str_replace('_', ' ', $offer['interestPayment'])) }}</td>
                                <td data-label="Valid until"><x-when :at="$offer['validUntil']" /></td>
                                @if ($canSelect)
                                    <td class="action">
                                        @if (\Carbon\CarbonImmutable::parse($offer['validUntil'])->isBefore($cutoff))
                                            <small class="muted">This offer expires too soon to complete the platform fee payment.</small>
                                        @else
                                            <button class="btn btn--primary btn--sm" name="offer_token" value="{{ $offer['token'] }}">Propose {{ $offer['label'] }}</button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canSelect)
                </form>
            @endif
        @endif
    </section>
</x-layout>
