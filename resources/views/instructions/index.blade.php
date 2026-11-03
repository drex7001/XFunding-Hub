<x-layout title="Settlement instructions">
    <x-page-header title="Settlement instructions" meta="Create a version and obtain independent approval, then attach it to an offer or a selection." />

    <div class="alert alert--info">Only your institution's treasury staff can read these details. The other institution on a deal receives the attached version after verified platform fee payment.</div>

    <div class="grid-main">
        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush"><h2>Version history</h2></div>
            @if ($records->isEmpty())
                <x-empty-state icon="key" title="No instruction versions yet" text="Offers and selections need an approved version." />
            @else
                <div class="table-wrap">
                    <table class="table table--stack">
                        <thead><tr><th>Version</th><th>Channel</th><th>Created</th><th>Status</th><th><span class="sr-only">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($records as $record)
                                <tr>
                                    <td class="primary"><a class="cell-title" href="{{ route('instructions.show', $record) }}">Version {{ $record->version }}</a></td>
                                    <td data-label="Channel">{{ strtoupper($record->details['channel']) }}</td>
                                    <td data-label="Created"><x-when :at="$record->created_at" /></td>
                                    <td data-label="Status"><x-status :value="$record->status" /></td>
                                    <td class="action"><a class="btn btn--secondary btn--sm" href="{{ route('instructions.show', $record) }}">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $records->links() }}
            @endif
        </section>

        @can('create', \App\Models\SettlementInstruction::class)
            <section class="panel">
                <h2>Propose a new version</h2>
                <p class="muted">Existing approved versions and selected offers remain fixed.</p>
                <form class="instruction-form" method="post" action="{{ route('instructions.store') }}">
                    @csrf
                    <label for="channel">Settlement channel</label>
                    <select id="channel" name="channel">
                        <option value="rtgs" @selected(old('channel') === 'rtgs')>Domestic RTGS</option>
                        <option value="swift" @selected(old('channel') === 'swift')>International SWIFT</option>
                    </select>
                    <div class="only-rtgs">
                        <label for="rtgs_code">RTGS routing code</label>
                        <input id="rtgs_code" name="rtgs_code" value="{{ old('rtgs_code') }}" maxlength="35">
                    </div>
                    <div class="only-swift">
                        <label for="bic">SWIFT BIC</label>
                        <input id="bic" name="bic" value="{{ old('bic') }}" maxlength="11">
                    </div>
                    <label for="account_name">Account holder</label>
                    <input id="account_name" name="account_name" value="{{ old('account_name') }}" maxlength="140" required>
                    <label for="account_number">Account number / IBAN</label>
                    <input id="account_number" name="account_number" value="{{ old('account_number') }}" maxlength="34" required>
                    <span class="hint">Uppercase letters and digits, without spaces.</span>
                    <label for="contact_name">Treasury contact name</label>
                    <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" maxlength="140" required>
                    <label for="contact_email">Treasury contact email</label>
                    <input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email') }}" maxlength="255" required>
                    <div class="btn-row"><button class="btn btn--primary">Propose instruction version</button></div>
                </form>
            </section>
        @endcan
    </div>
</x-layout>
