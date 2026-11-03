@php
    $user = auth()->user();
    $canEdit = $user->can('update', $bank);
    $canManage = $user->can('manageMembers', $bank);
    $capabilities = collect([$bank->can_borrow ? 'Borrower' : null, $bank->can_lend ? 'Lender' : null])->filter()->implode(' + ');
@endphp
<x-layout :title="$user->is_platform_admin ? $bank->name : 'My bank'">
    <x-page-header
        :title="$bank->name"
        :back="$user->is_platform_admin ? route('operations') : null"
        back-label="Institutions"
        :status="$bank->status->value"
        :meta="$bank->country_code.' · Registration '.$bank->registration_number"
    >
        @can('viewAudit', $bank)
            <a class="btn btn--secondary" href="{{ route('banks.audit', $bank) }}"><x-icon name="shield" /> Full audit history</a>
        @endcan
    </x-page-header>

    <div class="facts">
        <x-fact label="Verification" :hint="$bank->verified_at ? 'Reviewed '.$bank->verified_at->format('d M Y') : 'Platform review required'">{{ $bank->status === \App\BankStatus::Verified ? 'Verified institution' : ($bank->status === \App\BankStatus::Suspended ? 'Access suspended' : 'Onboarding in progress') }}</x-fact>
        <x-fact label="Approved capabilities" hint="Approved by platform operations.">{{ $capabilities ?: 'Awaiting verification' }}</x-fact>
        <x-fact label="Private documents" hint="Only authorized users can download.">{{ $bank->documents->count() }} onboarding {{ Str::plural('file', $bank->documents->count()) }}</x-fact>
    </div>

    @if ($user->is_platform_admin)
        <section class="next-step next-step--{{ $bank->status === \App\BankStatus::Pending ? 'action' : 'closed' }}">
            <span class="next-step__kicker">Platform verification</span>
            @if ($bank->status === \App\BankStatus::Pending)
                <h2>Verify this institution</h2>
                <form method="post" action="{{ route('banks.verify', $bank) }}">
                    @csrf
                    <span class="field-label">Approve requested capabilities</span>
                    <div class="checks">
                        @foreach ($bank->requested_capabilities as $capability)
                            <label><input type="checkbox" name="capabilities[]" value="{{ $capability }}"> {{ ucfirst($capability) }}</label>
                        @endforeach
                    </div>
                    <label for="verification-reason">Review reason</label>
                    <input id="verification-reason" name="reason" minlength="8" maxlength="500" required>
                    <div class="btn-row"><button class="btn btn--primary">Verify institution</button></div>
                </form>
                <details class="more">
                    <summary>Suspend instead</summary>
            @else
                <h2>{{ $bank->status === \App\BankStatus::Suspended ? 'This institution is suspended' : 'This institution is verified' }}</h2>
                <details class="more">
                    <summary>{{ $bank->status === \App\BankStatus::Suspended ? 'Reopen for review' : 'Suspend this institution' }}</summary>
            @endif
                    <form method="post" action="{{ $bank->status === \App\BankStatus::Suspended ? route('banks.reopen', $bank) : route('banks.suspend', $bank) }}">
                        @csrf
                        <label for="suspension-reason">{{ $bank->status === \App\BankStatus::Suspended ? 'Reason to reopen onboarding' : 'Reason to suspend access' }}</label>
                        <input id="suspension-reason" name="reason" minlength="8" maxlength="500" required>
                        <div class="btn-row"><button @class(['btn', 'btn--secondary' => $bank->status === \App\BankStatus::Suspended, 'btn--danger' => $bank->status !== \App\BankStatus::Suspended])>{{ $bank->status === \App\BankStatus::Suspended ? 'Reopen for review' : 'Suspend institution' }}</button></div>
                    </form>
                </details>
        </section>
    @endif

    <div class="grid-even section-gap">
        <section class="panel" id="profile">
            <h2>Institution profile</h2>
            @if ($canEdit)
                <form method="post" action="{{ route('banks.update', $bank) }}">
                    @csrf
                    @method('PATCH')
                    <label for="name">Legal bank name</label>
                    <input id="name" name="name" value="{{ old('name', $bank->name) }}" required maxlength="180">
                    <div class="form-row">
                        <div>
                            <label for="country">Country code</label>
                            <input id="country" name="country_code" value="{{ old('country_code', $bank->country_code) }}" maxlength="2" pattern="[A-Z]{2}" required>
                        </div>
                        <div>
                            <label for="registration">Registration number</label>
                            <input id="registration" name="registration_number" value="{{ old('registration_number', $bank->registration_number) }}" required maxlength="100">
                        </div>
                    </div>
                    <span class="field-label">Requested capabilities</span>
                    <div class="checks">
                        @foreach (['borrower', 'lender'] as $capability)
                            <label><input type="checkbox" name="requested_capabilities[]" value="{{ $capability }}" @checked(in_array($capability, $bank->requested_capabilities, true))> {{ ucfirst($capability) }}</label>
                        @endforeach
                    </div>
                    <span class="hint">Changing this profile returns the bank to pending verification and pauses its capabilities.</span>
                    <div class="btn-row"><button class="btn btn--secondary">Save profile for review</button></div>
                </form>
            @else
                <dl class="kv">
                    <dt>Legal name</dt><dd>{{ $bank->name }}</dd>
                    <dt>Country</dt><dd>{{ $bank->country_code }}</dd>
                    <dt>Requested capabilities</dt><dd>{{ ucfirst(implode(', ', $bank->requested_capabilities)) }}</dd>
                </dl>
            @endif
        </section>

        <section class="panel" id="documents">
            <h2>Onboarding documents</h2>
            @forelse ($bank->documents as $document)
                <div class="row">
                    <span class="doc-icon">PDF</span>
                    <div class="row__text">
                        <strong>{{ $document->original_name }}</strong>
                        <small>{{ number_format(max(1, $document->size / 1024)) }} KB · <x-when :at="$document->created_at" date-only /></small>
                    </div>
                    @if ($user->is_platform_admin)
                        <form class="row-form" method="get" action="{{ route('documents.show', $document) }}">
                            <div>
                                <label class="sr-only" for="reason-{{ $document->id }}">Review reason</label>
                                <input id="reason-{{ $document->id }}" name="reason" placeholder="Reason for access" minlength="8" required>
                            </div>
                            <button class="btn btn--secondary btn--sm">Download</button>
                        </form>
                    @else
                        <a class="link-button" href="{{ route('documents.show', $document) }}">Download</a>
                    @endif
                </div>
            @empty
                <p class="empty-line">No documents uploaded yet.</p>
            @endforelse
            @can('create', [\App\Models\BankDocument::class, $bank])
                <form class="more" method="post" enctype="multipart/form-data" action="{{ route('documents.store', $bank) }}">
                    @csrf
                    <label for="document">Add a verification document</label>
                    <input id="document" type="file" name="document" accept="application/pdf" required>
                    <span class="hint">PDF only, up to 10 MB.</span>
                    <div class="btn-row"><button class="btn btn--secondary btn--sm">Upload privately</button></div>
                </form>
            @endcan
        </section>
    </div>

    @if ($memberships->isNotEmpty())
        <section class="panel section-gap" id="team">
            <div class="panel__head"><h2>Team access</h2><span class="muted">Roles belong to this institution.</span></div>
            @foreach ($memberships as $membership)
                <div class="row">
                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($membership->user->name, 0, 1)) }}</span>
                    <div class="row__text">
                        <strong>{{ $membership->user->name }}</strong>
                        <small>{{ $membership->user->email }}</small>
                        <div class="role-list">
                            @foreach ($membership->roles as $role)
                                <span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
                            @endforeach
                        </div>
                    </div>
                    @unless ($membership->active)
                        <x-status value="suspended" label="Revoked" />
                    @endunless
                    @if ($canManage)
                        <details>
                            <summary>{{ $membership->active ? 'Manage roles' : 'Reinstate access' }}</summary>
                            <form method="post" action="{{ $membership->active ? route('access.roles', $membership) : route('access.reinstate', $membership) }}">
                                @csrf
                                <div class="checks checks--stack">
                                    @foreach ($roles as $role)
                                        <label><input type="checkbox" name="roles[]" value="{{ $role->value }}" @checked(in_array($role->value, $membership->roles, true))> {{ $role->label() }}</label>
                                    @endforeach
                                </div>
                                <div class="btn-row"><button class="btn btn--secondary btn--sm">{{ $membership->active ? 'Request role change' : 'Request reinstatement' }}</button></div>
                            </form>
                            @if ($membership->active)
                                <form method="post" action="{{ route('access.revoke', $membership) }}" class="btn-row">
                                    @csrf
                                    @method('DELETE')
                                    <button class="link-button link-button--danger">Revoke membership</button>
                                </form>
                            @endif
                        </details>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <div class="grid-even section-gap">
        @if ($user->is_platform_admin || $canManage)
            <section class="panel" id="access">
                <h2>Access approvals</h2>
                <x-access-requests :requests="$accessRequests" />
            </section>
        @endif
        @if ($canManage)
            <section class="panel">
                <h2>Invite a colleague</h2>
                <p class="muted">Invitations and role changes require approval by another bank admin or platform admin.</p>
                <form method="post" action="{{ route('access.store') }}">
                    @csrf
                    <div class="form-row">
                        <div>
                            <label for="invite-name">Full name</label>
                            <input id="invite-name" name="name" value="{{ old('name') }}" required maxlength="120">
                        </div>
                        <div>
                            <label for="invite-email">Work email</label>
                            <input id="invite-email" name="email" type="email" value="{{ old('email') }}" required>
                        </div>
                    </div>
                    <span class="field-label">Requested roles</span>
                    <div class="checks">
                        @foreach ($roles as $role)
                            <label><input type="checkbox" name="roles[]" value="{{ $role->value }}"> {{ $role->label() }}</label>
                        @endforeach
                    </div>
                    <div class="btn-row"><button class="btn btn--primary">Submit invitation for approval</button></div>
                </form>
            </section>
        @endif
    </div>

    <section class="panel" id="activity">
        <div class="panel__head">
            <h2>Recent activity</h2>
            @can('viewAudit', $bank)
                <a href="{{ route('banks.audit', $bank) }}">Full audit history</a>
            @else
                <span class="muted">Institution audit history</span>
            @endcan
        </div>
        @forelse ($events as $event)
            <div class="row">
                <div class="row__text">{{ ucfirst(str_replace(['.', '_'], ' ', $event->action)) }}</div>
                <small class="muted"><x-when :at="$event->created_at" /></small>
            </div>
        @empty
            <p class="empty-line">Your institution's activity will appear here.</p>
        @endforelse
    </section>
</x-layout>
