@props(['at', 'dateOnly' => false])
@php($moment = \Carbon\CarbonImmutable::parse($at))
<time datetime="{{ $moment->toIso8601String() }}">{{ $moment->format($dateOnly ? 'd M Y' : 'd M Y, H:i') }}@unless ($dateOnly) UTC @endunless</time>
