@props(['title', 'text' => null, 'icon' => 'check'])
<div {{ $attributes->class(['empty']) }}>
    <span class="empty__icon"><x-icon :name="$icon" /></span>
    <h2>{{ $title }}</h2>
    @if ($text)
        <p>{{ $text }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="btn-row">{{ $slot }}</div>
    @endif
</div>
