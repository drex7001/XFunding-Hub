@props(['tone' => 'action', 'title', 'kicker' => null])
@php($label = $kicker ?? match ($tone) { 'action' => 'Your next step', 'waiting' => 'Waiting on someone else', 'done' => 'Complete', default => 'Closed' })
<section {{ $attributes->class(['next-step', 'next-step--'.$tone]) }}>
    <span class="next-step__kicker">{{ $label }}</span>
    <h2>{{ $title }}</h2>
    {{ $slot }}
</section>
