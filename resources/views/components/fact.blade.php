@props(['label', 'hint' => null])
<div class="fact">
    <span class="fact__label">{{ $label }}</span>
    <span class="fact__value">{{ $slot }}</span>
    @if ($hint)
        <span class="fact__hint">{{ $hint }}</span>
    @endif
</div>
