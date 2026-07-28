
@props(['requests'])
@forelse ($requests as $accessRequest)<div class="review-row"><div><strong>{{ $accessRequest->name }}</strong><small>{{ $accessRequest->email }} · {{ str_replace('_', ' ', $accessRequest->kind) }}</small><div class="role-list">
@foreach ($accessRequest->roles as $role)<span class="tag">{{ \App\BankRole::from($role)->label() }}</span>
@endforeach</div>
</div>
<div class="review-actions"><span class="badge {{ $accessRequest->status }}">{{ ucfirst($accessRequest->status) }}</span>
@if ($accessRequest->status === 'pending')
@can('review', $accessRequest)<form method="post" action="{{ route('access.review', $accessRequest) }}">
@csrf<button class="button compact" name="decision" value="approve">Approve</button><button class="button secondary compact" name="decision" value="reject">Reject</button></form>
@else<small>Another authorized reviewer is required.</small>
@endcan
@endif</div>
</div>
@empty<p class="empty">No access requests to review.</p>@endforelse
