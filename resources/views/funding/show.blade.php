@php
    $status = $record->currentStatus();
    $approval = $record->pendingApproval;
    $can = $data['can'] ?? [];
    $user = auth()->user();
    $canAddFiles = $user->can('create', [\App\Models\FundingDocument::class, $record]);
    $canOpenDeal = $record->deal && $user->can('view', $record->deal);
    $canCompare = $owned && in_array($status, [\App\FundingRequestStatus::Open, \App\FundingRequestStatus::Selected, \App\FundingRequestStatus::Settled], true);
@endphp
<x-layout title="Funding request">
    <x-page-header
        :title="$record->title"
        :eyebrow="$data['borrower']['name']"
        :back="$owned ? route('funding.index') : route('marketplace')"
        :back-label="$owned ? 'Funding requests' : 'Marketplace'"
        :status="$status"
        :status-label="$status->label()"
        :meta="'Ref '.strtoupper(substr($record->id, -8)).($owned ? ' · Revision '.$record->version : '')"
    >
        @if ($can['update'] ?? false)
            <a class="btn btn--secondary" href="{{ route('funding.edit', $record) }}">Edit draft</a>
        @endif
        @if ($canCompare && $status !== \App\FundingRequestStatus::Open)
            <a class="btn btn--secondary" href="{{ route('funding.offers', $record) }}">Offers</a>
        @endif
    </x-page-header>

    @if ($owned)
        <x-stepper :steps="$progress" />
    @endif

    @if ($owned)
        @if ($status === \App\FundingRequestStatus::Settled)
            <x-next-step tone="done" title="This request is settled">
                <p>Both institutions independently confirmed the off-platform settlement.</p>
                @if ($canOpenDeal)
                    <div class="btn-row"><a class="btn btn--secondary" href="{{ route('deals.show', $record->deal) }}">View selected deal</a></div>
                @endif
            </x-next-step>
        @elseif ($status === \App\FundingRequestStatus::Expired)
            <x-next-step tone="closed" title="The bidding deadline passed without a selection">
                <p>An expired request cannot be published or revived. Prepare a new request if you still need liquidity.</p>
                @can('create', \App\Models\FundingRequest::class)
                    <div class="btn-row"><a class="btn btn--secondary" href="{{ route('funding.create') }}">New funding request</a></div>
                @endcan
            </x-next-step>
        @elseif ($status === \App\FundingRequestStatus::Cancelled)
            <x-next-step tone="closed" title="This request was cancelled">
                <p>Prepare a new request if you still need liquidity.</p>
                @if ($canOpenDeal)
                    <div class="btn-row"><a class="btn btn--secondary" href="{{ route('deals.show', $record->deal) }}">View selected deal</a></div>
                @endif
            </x-next-step>
        @elseif ($status === \App\FundingRequestStatus::Selected)
            <x-next-step title="An offer is selected. Continue on the deal">
                <p>The platform fee, settlement details and both banks' confirmations are handled on the deal.</p>
                @if ($canOpenDeal)
                    <div class="btn-row"><a class="btn btn--primary" href="{{ route('deals.show', $record->deal) }}">View selected deal <x-icon name="arrow-right" /></a></div>
                @endif
            </x-next-step>
        @elseif ($approval)
            @php($approvalName = $approval->kind === 'publish' ? 'publication' : 'cancellation')
            @if ($can['review'])
                <x-next-step :title="'Approve '.$approvalName">
                    <p>{{ $approval->proposal_reason ?: 'Check the terms and documents below, then record your independent decision.' }}</p>
                    <x-decision-form :action="route('funding.review', ['fundingRequest' => $record, 'approval' => $approval])" :approve="'Approve '.$approvalName" reject="Request changes / reject" id="review-reason" />
                </x-next-step>
            @else
                <x-next-step tone="waiting" :title="ucfirst($approvalName).' approval pending'">
                    <p>{{ $approval->proposal_reason ?: 'An independent treasury approver must review the current terms and document set.' }} Publication contributors cannot approve their own request.</p>
                </x-next-step>
            @endif
        @elseif ($status === \App\FundingRequestStatus::Open)
            @if ($record->pendingSelection)
                <x-next-step tone="waiting" title="A selection is awaiting independent approval">
                    <p>A different borrower treasury approver reviews the proposed offer.</p>
                    <div class="btn-row"><a class="btn btn--secondary" href="{{ route('funding.offers', $record) }}">Open the comparison</a></div>
                </x-next-step>
            @elseif ($activeOffers > 0)
                <x-next-step :title="'Compare '.$activeOffers.' '.Str::plural('offer', $activeOffers)">
                    <p>Bidding closes <x-when :at="$record->bidding_deadline" />. Lenders stay anonymous until the platform fee is verified.</p>
                    <div class="btn-row"><a class="btn btn--primary" href="{{ route('funding.offers', $record) }}">Compare offers <x-icon name="arrow-right" /></a></div>
                </x-next-step>
            @else
                <x-next-step tone="waiting" title="Waiting for lender offers">
                    <p>The request is published. Approved offers appear in the comparison until bidding closes <x-when :at="$record->bidding_deadline" />.</p>
                    <div class="btn-row"><a class="btn btn--secondary" href="{{ route('funding.offers', $record) }}">Open the comparison</a></div>
                </x-next-step>
            @endif
        @elseif ($can['submit'])
            <x-next-step title="Submit for publication approval">
                <p>The terms and {{ $record->documents->count() }} {{ Str::plural('document', $record->documents->count()) }} below go to an independent treasury approver.</p>
                <form method="post" action="{{ route('funding.submit', $record) }}">
                    @csrf
                    <div class="btn-row"><button class="btn btn--primary">Submit for publication approval</button></div>
                </form>
            </x-next-step>
        @elseif ($record->documents->isEmpty() && $canAddFiles)
            <x-next-step title="Attach financial documents">
                <p>Upload at least one financial PDF. You can then submit the request for approval.</p>
                <div class="btn-row"><a class="btn btn--primary" href="#documents">Add a PDF</a></div>
            </x-next-step>
        @elseif (! $record->bidding_deadline->isFuture() && ($can['update'] ?? false))
            <x-next-step title="Update the bidding deadline">
                <p>The deadline on this draft has passed. Choose a future deadline before submitting it.</p>
                <div class="btn-row"><a class="btn btn--primary" href="{{ route('funding.edit', $record) }}">Edit draft</a></div>
            </x-next-step>
        @else
            <x-next-step tone="waiting" title="A treasury maker is preparing this draft">
                <p>A treasury maker completes the terms and documents, then submits the request for approval.</p>
            </x-next-step>
        @endif
    @elseif ($canOpenDeal)
        <x-next-step title="Your offer was selected">
            <p>Terms are reserved. Continue on the deal.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('deals.show', $record->deal) }}">View selected deal <x-icon name="arrow-right" /></a></div>
        </x-next-step>
    @elseif ($ownOffer && in_array($ownOffer->currentStatus(), [\App\OfferStatus::Draft, \App\OfferStatus::PendingApproval, \App\OfferStatus::Active], true))
        <x-next-step tone="waiting" :title="'Your bank has an offer on this request: '.$ownOffer->currentStatus()->label()" kicker="Your offer">
            <p>Bidding closes <x-when :at="$record->bidding_deadline" />. Your bank can hold one active offer per request.</p>
            <div class="btn-row"><a class="btn btn--secondary" href="{{ route('offers.show', $ownOffer) }}">Open your offer</a></div>
        </x-next-step>
    @elseif ($user->can('create', [\App\Models\Offer::class, $record]))
        <x-next-step title="Prepare an offer">
            <p>Review the borrower profile and documents below. Bidding closes <x-when :at="$record->bidding_deadline" />. The borrower compares offers without seeing your identity.</p>
            <div class="btn-row"><a class="btn btn--primary" href="{{ route('offers.create', $record) }}">Prepare an offer <x-icon name="arrow-right" /></a></div>
        </x-next-step>
    @else
        <x-next-step tone="waiting" title="A treasury maker at your bank prepares offers">
            <p>Bidding closes <x-when :at="$record->bidding_deadline" />.</p>
        </x-next-step>
    @endif

    <div class="facts">
        <x-fact label="Required liquidity" :hint="$record->tenor_days.' day tenor'"><x-money :currency="$record->currency" :amount="$record->amount" /></x-fact>
        <x-fact label="Expected settlement" :hint="$record->day_count.' · Annualized simple rate'"><x-when :at="$record->settlement_date" date-only /></x-fact>
        <x-fact label="Bidding deadline"><x-when :at="$record->bidding_deadline" /></x-fact>
    </div>

    @if ($record->status === \App\FundingRequestStatus::Open)
        <div class="alert alert--info">Published financial terms, borrower profile and document set are fixed for this request.</div>
    @endif

    <div class="grid-main section-gap">
        <section class="panel">
            <div class="panel__head">
                <h2>Borrower profile</h2>
                <span class="tag">{{ $record->published_at ? 'Published snapshot' : 'Review profile' }}</span>
            </div>
            <dl class="kv">
                <dt>Bank name</dt><dd>{{ $data['borrower']['name'] }}</dd>
                <dt>Country / registration</dt><dd>{{ $data['borrower']['countryCode'] }} / {{ $data['borrower']['registrationNumber'] }}</dd>
            </dl>
            <h3 class="subhead">Background and financial history</h3>
            <p class="long-text">{{ $data['borrower']['background'] }}</p>
        </section>

        <section class="panel" id="documents">
            <div class="panel__head">
                <h2>Financial documents</h2>
                <span class="tag">{{ $record->documents->count() }} {{ Str::plural('file', $record->documents->count()) }}</span>
            </div>
            @forelse ($record->documents as $document)
                <div class="row">
                    <span class="doc-icon">PDF</span>
                    <div class="row__text">
                        <strong>{{ $document->original_name }}</strong>
                        <small>{{ number_format(max(1, $document->size / 1024)) }} KB</small>
                    </div>
                    <div class="row-actions">
                        <a class="link-button" href="{{ route('funding.documents.show', $document) }}">Download</a>
                        @can('delete', $document)
                            <form method="post" action="{{ route('funding.documents.destroy', $document) }}">
                                @csrf
                                @method('DELETE')
                                <button class="link-button link-button--danger">Remove</button>
                            </form>
                        @endcan
                    </div>
                </div>
            @empty
                <p class="empty-line">Attach at least one financial PDF before submitting for approval.</p>
            @endforelse
            @if ($canAddFiles)
                <form class="more" method="post" enctype="multipart/form-data" action="{{ route('funding.documents.store', $record) }}">
                    @csrf
                    <label for="document">Add financial evidence</label>
                    <input id="document" type="file" name="document" accept="application/pdf" required>
                    <span class="hint">PDF only, up to 10 MB. Lenders see these files after publication.</span>
                    <div class="btn-row"><button class="btn btn--secondary btn--sm">Upload privately</button></div>
                </form>
            @endif
        </section>
    </div>

    @if ($owned)
        <div @class(['grid-even' => $can['proposeCancellation'], 'stack' => ! $can['proposeCancellation']])>
            <section class="panel">
                <h2>Approval history</h2>
                @forelse ($history as $item)
                    <div class="row">
                        <div class="row__text">
                            <strong>{{ ucfirst($item->kind) }} · Revision {{ $item->expected_version }}</strong>
                            @if ($item->reason ?: $item->proposal_reason)
                                <small>{{ $item->reason ?: $item->proposal_reason }}</small>
                            @endif
                        </div>
                        <x-status :value="$item->status" />
                    </div>
                @empty
                    <p class="empty-line">No approval decisions yet.</p>
                @endforelse
            </section>
            @if ($can['proposeCancellation'])
                <details class="panel">
                    <summary>Cancel this request</summary>
                    <form method="post" action="{{ route('funding.cancel', $record) }}">
                        @csrf
                        <label for="cancel-reason">Reason for cancellation</label>
                        <textarea id="cancel-reason" name="reason" rows="2" minlength="5" maxlength="500" required></textarea>
                        <span class="hint">A different approver must approve the cancellation.</span>
                        <div class="btn-row"><button class="btn btn--danger">Send cancellation for approval</button></div>
                    </form>
                </details>
            @endif
        </div>
    @endif
</x-layout>
