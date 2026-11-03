@props(['href', 'icon', 'active' => false, 'count' => 0, 'quiet' => false])
<a @class(['nav-item', 'is-active' => $active]) href="{{ $href }}" @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" />
    {{ $slot }}
    @if ($count > 0)
        <span @class(['nav-count', 'nav-count--quiet' => $quiet])>{{ $count }}</span>
    @endif
</a>
