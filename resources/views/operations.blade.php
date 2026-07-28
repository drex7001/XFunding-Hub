<x-layout title="Bank operations">
<div class="page-heading"><div><span class="eyebrow">PLATFORM OPERATIONS</span><h1>Build a trusted network.</h1><p class="muted">Review institutions and manage independent access approvals.</p></div>
<span class="badge verified">Platform admin</span></div>
<div class="content-grid"><section class="panel"><div class="panel-heading"><h2>Institutions</h2><span class="tag">{{ $banks->total() }} banks</span></div>
@forelse ($banks as $bank)<a class="bank-row" href="{{ route('banks.show', $bank) }}"><span class="bank-icon">{{ mb_strtoupper(mb_substr($bank->name, 0, 1)) }}</span><div><strong>{{ $bank->name }}</strong><small>{{ $bank->country_code }} · {{ $bank->registration_number }}</small></div>
<span class="badge {{ $bank->status->value }}">{{ ucfirst($bank->status->value) }}</span><span>→</span></a>
@empty<p class="empty">Create your first institution to begin onboarding.</p>@endforelse<div class="pagination">{{ $banks->links() }}</div>
</section>
<section class="panel"><span class="eyebrow">ONBOARD AN INSTITUTION</span><h2>Create a bank</h2><p class="muted">The first bank admin receives an invitation. Marketplace access starts after verification.</p><form method="post" action="{{ route('banks.store') }}">
@csrf<label for="name">Legal bank name</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="180"><div class="form-grid"><div><label for="country">Country code</label><input id="country" name="country_code" value="{{ old('country_code') }}" placeholder="LK" maxlength="2" pattern="[A-Z]{2}" required></div>
<div><label for="registration">Registration number</label><input id="registration" name="registration_number" value="{{ old('registration_number') }}" required maxlength="100"></div>
</div>
<label>Requested capabilities</label><div class="checks"><label><input type="checkbox" name="requested_capabilities[]" value="borrower">Borrower</label><label><input type="checkbox" name="requested_capabilities[]" value="lender">Lender</label></div>
<label for="admin-name">First bank admin</label><input id="admin-name" name="admin_name" value="{{ old('admin_name') }}" required maxlength="120"><label for="admin-email">Admin work email</label><input id="admin-email" name="admin_email" type="email" value="{{ old('admin_email') }}" required><button class="button full">Create bank & invite admin</button></form>
</section>
</div>
<section class="panel"><div class="panel-heading"><h2>Independent access review</h2><span class="muted">Pending requests</span></div>
<x-access-requests :requests="$accessRequests" /></section>
</x-layout>
