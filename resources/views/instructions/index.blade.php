<x-layout title="Settlement instructions">
    <div class="page-heading"><div><span class="eyebrow">LENDER TREASURY</span><h1>Approved banking instructions.</h1><p class="muted">Create a version, obtain independent approval, then attach it to your offers.</p></div></div>
    <div class="notice">Only your institution’s treasury staff can read these details. Borrowers receive them after verified platform fee payment.</div>
    <div class="content-grid"><section class="panel"><h2>Version history</h2>
        @forelse ($records as $record)
            <a class="review-row" href="{{ route('instructions.show', $record) }}"><div><strong>Version {{ $record->version }}</strong><small>{{ strtoupper($record->details['channel']) }} · {{ $record->created_at->format('d M Y, H:i') }} UTC</small></div><span class="badge {{ $record->status }}">{{ ucfirst($record->status) }}</span></a>
        @empty
            <p class="empty">No instruction versions yet.</p>
        @endforelse
        {{ $records->links() }}
    </section>
    @can('create', \App\Models\SettlementInstruction::class)
        <section class="panel"><h2>Propose a new version</h2><p class="muted">Existing approved versions and selected offers remain fixed.</p>
            <form method="post" action="{{ route('instructions.store') }}">
                @csrf
                <label for="channel">Settlement channel</label><select id="channel" name="channel"><option value="rtgs" @selected(old('channel') === 'rtgs')>Domestic RTGS</option><option value="swift" @selected(old('channel') === 'swift')>International SWIFT</option></select>
                <label for="account_name">Account holder</label><input id="account_name" name="account_name" value="{{ old('account_name') }}" maxlength="140" required>
                <label for="account_number">Account number / IBAN</label><input id="account_number" name="account_number" value="{{ old('account_number') }}" maxlength="34" required><small>Uppercase letters and digits, without spaces.</small>
                <div class="form-columns"><div><label for="rtgs_code">RTGS routing code</label><input id="rtgs_code" name="rtgs_code" value="{{ old('rtgs_code') }}" maxlength="35"></div><div><label for="bic">SWIFT BIC</label><input id="bic" name="bic" value="{{ old('bic') }}" maxlength="11"></div></div><small>Fill the routing code for your selected channel.</small>
                <label for="contact_name">Treasury contact name</label><input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" maxlength="140" required>
                <label for="contact_email">Treasury contact email</label><input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email') }}" maxlength="255" required>
                <button class="button">Propose instruction version</button>
            </form>
        </section>
    @endcan
    </div>
</x-layout>
