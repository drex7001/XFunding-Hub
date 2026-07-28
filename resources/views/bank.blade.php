<x-layout title="Bank workspace">
<div class="page-heading"><div><span class="eyebrow">YOUR INSTITUTION</span><h1>{{ $bank->name }}</h1><p class="muted">{{ $bank->country_code }} · Registration {{ $bank->registration_number }}</p></div>
<span class="badge {{ $bank->status->value }}">{{ ucfirst($bank->status->value) }}</span></div>
<div class="summary-grid"><div class="summary"><span class="eyebrow">VERIFICATION</span><strong>{{ $bank->status === \App\BankStatus::Verified ? 'Verified institution' : ($bank->status === \App\BankStatus::Suspended ? 'Access suspended' : 'Onboarding in progress') }}</strong><small>{{ $bank->verified_at ? 'Reviewed '.$bank->verified_at->format('d M Y') : 'Platform review required' }}</small></div>
<div class="summary"><span class="eyebrow">APPROVED CAPABILITIES</span><strong>{{ collect([$bank->can_borrow ? 'Borrower' : null, $bank->can_lend ? 'Lender' : null])->filter()->implode(' + ') ?: 'Awaiting verification' }}</strong><small>Approved by platform operations.</small></div>
<div class="summary"><span class="eyebrow">PRIVATE DOCUMENTS</span><strong>{{ $bank->documents->count() }} onboarding files</strong><small>Only authorized users can download.</small></div>
</div>
<div class="content-grid"><section class="panel"><h2>Institution profile</h2>
@can('update', $bank)<form method="post" action="{{ route('banks.update', $bank) }}">
@csrf
@method('PATCH')<label for="name">Legal bank name</label><input id="name" name="name" value="{{ old('name', $bank->name) }}" required maxlength="180"><div class="form-grid"><div><label for="country">Country code</label><input id="country" name="country_code" value="{{ old('country_code', $bank->country_code) }}" maxlength="2" pattern="[A-Z]{2}" required></div>
<div><label for="registration">Registration number</label><input id="registration" name="registration_number" value="{{ old('registration_number', $bank->registration_number) }}" required maxlength="100"></div>
</div>
<label>Requested capabilities</label><div class="checks">
@foreach (['borrower', 'lender'] as $capability)<label><input type="checkbox" name="requested_capabilities[]" value="{{ $capability }}"
@checked(in_array($capability, $bank->requested_capabilities, true))>{{ ucfirst($capability) }}</label>
@endforeach</div>
<p class="muted">Saving changes requires a new platform verification.</p><button class="button">Save profile for review</button></form>
@else<dl><dt>Legal name</dt><dd>{{ $bank->name }}</dd><dt>Country</dt><dd>{{ $bank->country_code }}</dd><dt>Requested capabilities</dt><dd>{{ implode(', ', $bank->requested_capabilities) }}</dd></dl>
@endcan
</section>
<section class="panel"><h2>Onboarding documents</h2><p class="muted">Private PDF documents for institution verification.</p>
@forelse ($bank->documents as $document)<div class="document-row"><span class="document-icon">PDF</span><div><strong>{{ $document->original_name }}</strong><small>{{ number_format($document->size / 1024) }} KB · {{ $document->created_at->format('d M Y') }}</small></div>
@if (auth()->user()->is_platform_admin)<form method="get" action="{{ route('documents.show', $document) }}"><label class="sr-only" for="reason-{{ $document->id }}">Review reason</label><input id="reason-{{ $document->id }}" name="reason" placeholder="Reason for access" minlength="8" required><button class="text-button">Download</button></form>
@else<a class="text-button" href="{{ route('documents.show', $document) }}">Download ↓</a>
@endif</div>
@empty<p class="empty">No documents uploaded yet.</p>@endforelse
@can('create', [\App\Models\BankDocument::class, $bank])<form class="upload" method="post" enctype="multipart/form-data" action="{{ route('documents.store', $bank) }}">
@csrf<label for="document">Add a verification document</label><input id="document" type="file" name="document" accept="application/pdf" required><small>PDF only. Maximum 10 MB.</small><button class="button secondary">Upload privately</button></form>
@endcan
</section>
</div>
@if (auth()->user()->is_platform_admin)<section class="panel"><h2>Platform verification</h2>
@if ($bank->status === \App\BankStatus::Pending)<form method="post" action="{{ route('banks.verify', $bank) }}">
@csrf<label>Approve requested capabilities</label><div class="checks">
@foreach ($bank->requested_capabilities as $capability)<label><input type="checkbox" name="capabilities[]" value="{{ $capability }}">{{ ucfirst($capability) }}</label>
@endforeach</div>
<label for="verification-reason">Review reason</label><input id="verification-reason" name="reason" minlength="8" maxlength="500" required><button class="button">Verify institution</button></form>
@endif<form class="separated" method="post" action="{{ $bank->status === \App\BankStatus::Suspended ? route('banks.reopen', $bank) : route('banks.suspend', $bank) }}">
@csrf<label for="suspension-reason">{{ $bank->status === \App\BankStatus::Suspended ? 'Reason to reopen onboarding' : 'Reason to suspend access' }}</label><input id="suspension-reason" name="reason" minlength="8" maxlength="500" required><button class="button secondary">{{ $bank->status === \App\BankStatus::Suspended ? 'Reopen for review' : 'Suspend institution' }}</button></form>
</section>
@endif
@if ($memberships->isNotEmpty())<section class="panel"><div class="panel-heading"><h2>Team access</h2><span class="muted">Roles belong to this institution.</span></div>
@foreach ($memberships as $membership)<div class="member-row"><div><strong>{{ $membership->user->name }}</strong><small>{{ $membership->user->email }} · {{ $membership->active ? 'Active' : 'Revoked' }}</small><div class="role-list">
@foreach ($membership->roles as $role)<span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
@endforeach</div>
</div>
@can('manageMembers', $bank)
@if ($membership->active)<details><summary>Manage roles</summary><form method="post" action="{{ route('access.roles', $membership) }}">
@csrf<div class="checks vertical">
@foreach ($roles as $role)<label><input type="checkbox" name="roles[]" value="{{ $role->value }}"
@checked(in_array($role->value, $membership->roles, true))>{{ $role->label() }}</label>
@endforeach</div>
<button class="button secondary compact">Request role change</button></form>
<form class="separated" method="post" action="{{ route('access.revoke', $membership) }}">
@csrf
@method('DELETE')<button class="text-button danger">Revoke membership</button></form>
</details>
@endif
@endcan</div>
@endforeach</section>
@endif
@can('manageMembers', $bank)<section class="panel"><span class="eyebrow">GROW YOUR TEAM</span><h2>Invite a colleague</h2><p class="muted">Invitations and role changes require approval by another bank admin or platform admin.</p><form method="post" action="{{ route('access.store') }}">
@csrf<div class="form-grid"><div><label for="invite-name">Full name</label><input id="invite-name" name="name" value="{{ old('name') }}" required maxlength="120"></div>
<div><label for="invite-email">Work email</label><input id="invite-email" name="email" type="email" value="{{ old('email') }}" required></div>
</div>
<label>Requested roles</label><div class="checks">
@foreach ($roles as $role)<label><input type="checkbox" name="roles[]" value="{{ $role->value }}">{{ $role->label() }}</label>
@endforeach</div>
<button class="button">Submit invitation for approval</button></form>
</section>
@endcan
@if (auth()->user()->is_platform_admin || auth()->user()->can('manageMembers', $bank))<section class="panel"><h2>Access approvals</h2><x-access-requests :requests="$accessRequests" /></section>
@endif
<section class="panel"><div class="panel-heading"><h2>Recent activity</h2><span class="muted">Institution audit history</span></div>
@forelse ($events as $event)<div class="activity"><span>{{ ucfirst(str_replace(['.', '_'], ' ', $event->action)) }}</span><time>{{ $event->created_at->format('d M Y, H:i') }} UTC</time></div>
@empty<p class="empty">Your institution's activity will appear here.</p>@endforelse</section>
</x-layout>
