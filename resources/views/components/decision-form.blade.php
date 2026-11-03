@props(['action', 'approve', 'reject' => 'Request changes', 'id' => 'review-note'])
<form method="post" action="{{ $action }}">
    @csrf
    <label for="{{ $id }}">Review note <span class="muted">(required when rejecting)</span></label>
    <textarea id="{{ $id }}" name="reason" rows="2" maxlength="500"></textarea>
    <div class="btn-row">
        <button class="btn btn--primary" name="decision" value="approve">{{ $approve }}</button>
        <button class="btn btn--secondary" name="decision" value="reject">{{ $reject }}</button>
    </div>
</form>
