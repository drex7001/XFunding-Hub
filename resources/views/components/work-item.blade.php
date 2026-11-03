@props(['item', 'waiting' => false])
<a @class(['work-item', 'work-item--waiting' => $waiting]) href="{{ $item['url'] }}">
    <span class="work-item__text">
        <span class="work-item__task">{{ $item['task'] }}</span>
        <span class="work-item__title">{{ $item['title'] }}</span>
        <span class="work-item__meta">{{ $waiting ? $item['note'] : $item['detail'] }}</span>
        @if ($item['due'])
            <span class="due" title="{{ $item['due']->format('d M Y, H:i') }} UTC"><x-icon name="clock" /> {{ $item['due']->isFuture() ? 'Due '.$item['due']->diffForHumans() : 'Overdue' }}</span>
        @endif
    </span>
    @unless ($waiting)
        <span class="btn btn--secondary btn--sm">{{ $item['cta'] }}</span>
    @endunless
</a>
