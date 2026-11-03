@props(['value', 'label' => null])
@php
    $tone = match ($value) {
        'verified', 'approved', 'open', 'active', 'selected', 'unlocked', 'settled', 'refunded' => 'success',
        'pending', 'pending_approval', 'awaiting_fee', 'refund_review', 'disputed' => 'warning',
        'suspended', 'rejected', 'closed_unsettled', 'failed' => 'danger',
        default => 'neutral',
    };
@endphp
<span {{ $attributes->class(['badge', 'badge--'.$tone]) }}>{{ $label ?? ucfirst(str_replace('_', ' ', $value)) }}</span>
