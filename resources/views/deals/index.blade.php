@php($bankId = auth()->user()->membership->bank_id)
<x-layout title="Selected deals">
    <x-page-header title="Selected deals" meta="Track platform fees, settlement details and each bank's confirmation." />

    <section class="panel panel--flush">
        @if ($records->isEmpty())
            <x-empty-state icon="layers" title="No selected deals yet" text="Approved selections appear here for the participating institutions." />
        @else
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Deal</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Tenor</th>
                            <th>Platform fee deadline</th>
                            <th>Status</th>
                            <th><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td class="primary">
                                    <a class="cell-title" href="{{ route('deals.show', $record) }}">{{ $record->terms['borrower']['name'] }}</a>
                                    <span class="cell-sub">{{ $record->labelFor($bankId) }} · Ref {{ strtoupper(substr($record->id, -8)) }}</span>
                                </td>
                                <td data-label="Rate"><span class="rate">{{ $record->terms['rate'] }}%</span></td>
                                <td data-label="Amount"><x-money :currency="$record->terms['currency']" :amount="$record->terms['amount']" /></td>
                                <td data-label="Tenor">{{ $record->terms['tenorDays'] }} days</td>
                                <td data-label="Platform fee deadline">
                                    @if ($record->fee_deadline)
                                        <x-when :at="$record->fee_deadline" />
                                    @else
                                        Fee quote pending
                                    @endif
                                </td>
                                <td data-label="Status"><x-status :value="$record->currentStatus()->value" /></td>
                                <td class="action"><a class="btn btn--secondary btn--sm" href="{{ route('deals.show', $record) }}">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $records->links() }}
        @endif
    </section>
</x-layout>
