@props(['at', 'dateOnly' => false])
@php $moment = \Carbon\CarbonImmutable::parse($at); @endphp
<time datetime="{{ $moment->toIso8601String() }}">{{ $moment->format($dateOnly ? 'd M Y' : 'd M Y, H:i \U\T\C') }}</time>@php /* Swallows the final newline so callers can follow with punctuation. */ @endphp
