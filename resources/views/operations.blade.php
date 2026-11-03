<x-layout title="Institutions">
    <x-page-header title="Institutions" meta="Review institutions and manage independent access approvals." />

    <div class="grid-main section-gap">
        <section class="panel panel--flush">
            <div class="panel__head panel__head--flush"><h2>Banks</h2><span class="tag">{{ $banks->total() }} total</span></div>
            @if ($banks->isEmpty())
                <x-empty-state icon="bank" title="No institutions yet" text="Create your first institution to begin onboarding." />
            @else
                <div class="table-wrap">
                    <table class="table table--stack">
                        <thead><tr><th>Institution</th><th>Status</th><th><span class="sr-only">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($banks as $bank)
                                <tr>
                                    <td class="primary">
                                        <a class="cell-title" href="{{ route('banks.show', $bank) }}">{{ $bank->name }}</a>
                                        <span class="cell-sub">{{ $bank->country_code }} · {{ $bank->registration_number }}</span>
                                    </td>
                                    <td data-label="Status"><x-status :value="$bank->status->value" /></td>
                                    <td class="action"><a class="btn btn--secondary btn--sm" href="{{ route('banks.show', $bank) }}">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $banks->links() }}
            @endif
        </section>

        <section class="panel">
            <h2>Create a bank</h2>
            <p class="muted">The first bank admin receives an invitation. Marketplace access starts after verification.</p>
            <form method="post" action="{{ route('banks.store') }}">
                @csrf
                <label for="name">Legal bank name</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="180">
                <div class="form-row">
                    <div>
                        <label for="country">Country code</label>
                        <input id="country" name="country_code" value="{{ old('country_code') }}" placeholder="LK" maxlength="2" pattern="[A-Z]{2}" required>
                    </div>
                    <div>
                        <label for="registration">Registration number</label>
                        <input id="registration" name="registration_number" value="{{ old('registration_number') }}" required maxlength="100">
                    </div>
                </div>
                <span class="field-label">Requested capabilities</span>
                <div class="checks">
                    <label><input type="checkbox" name="requested_capabilities[]" value="borrower"> Borrower</label>
                    <label><input type="checkbox" name="requested_capabilities[]" value="lender"> Lender</label>
                </div>
                <label for="admin-name">First bank admin</label>
                <input id="admin-name" name="admin_name" value="{{ old('admin_name') }}" required maxlength="120">
                <label for="admin-email">Admin work email</label>
                <input id="admin-email" name="admin_email" type="email" value="{{ old('admin_email') }}" required>
                <div class="btn-row"><button class="btn btn--primary btn--full">Create bank and invite admin</button></div>
            </form>
        </section>
    </div>

    <section class="panel">
        <div class="panel__head"><h2>Independent access review</h2><span class="muted">Pending requests</span></div>
        <x-access-requests :requests="$accessRequests" />
    </section>
</x-layout>
