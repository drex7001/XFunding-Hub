@props(['title', 'eyebrow' => null, 'back' => null, 'backLabel' => null, 'status' => null, 'statusLabel' => null, 'meta' => null])
<header class="page-header">
    @if ($back)
        <a class="back-link" href="{{ $back }}"><x-icon name="arrow-left" /> {{ $backLabel }}</a>
    @endif
    <div class="page-header__row">
        <div>
            @if ($eyebrow)
                <p class="eyebrow">{{ $eyebrow }}</p>
            @endif
            <div class="title-line">
                <h1>{{ $title }}</h1>
                @if ($status)
                    <x-status :value="$status" :label="$statusLabel" />
                @endif
            </div>
            @if ($meta)
                <p class="page-meta">{{ $meta }}</p>
            @endif
        </div>
        @if ($slot->isNotEmpty())
            <div class="page-actions">{{ $slot }}</div>
        @endif
    </div>
</header>
