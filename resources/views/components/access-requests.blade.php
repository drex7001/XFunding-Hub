@props(['requests'])
@forelse ($requests as $accessRequest)
    <div class="row">
        <div class="row__text">
            <strong>{{ $accessRequest->name }}</strong>
            <small>{{ $accessRequest->email }} · {{ ucfirst(str_replace('_', ' ', $accessRequest->kind)) }}</small>
            <div class="role-list">
                @foreach ($accessRequest->roles as $role)
                    <span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
                @endforeach
            </div>
        </div>
        <div class="row-actions">
            @if ($accessRequest->status === 'pending')
                @can('review', $accessRequest)
                    <form method="post" action="{{ route('access.review', $accessRequest) }}" class="btn-row btn-row--flat">
                        @csrf
                        <button class="btn btn--primary btn--sm" name="decision" value="approve">Approve</button>
                        <button class="btn btn--secondary btn--sm" name="decision" value="reject">Reject</button>
                    </form>
                @else
                    <small class="muted">Another authorized reviewer is required.</small>
                @endcan
            @else
                <x-status :value="$accessRequest->status" />
            @endif
        </div>
    </div>
@empty
    <p class="empty-line">No access requests to review.</p>
@endforelse
