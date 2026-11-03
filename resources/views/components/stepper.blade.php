@props(['steps'])
@php
    $focusIndex = collect($steps)->search(fn (array $step): bool => in_array($step['state'], ['current', 'stopped'], true));
    $focusIndex = $focusIndex === false ? array_key_last($steps) : $focusIndex;
@endphp
<div class="stepper">
    <ol class="stepper__steps" aria-label="Progress">
        @foreach ($steps as $step)
            <li class="step step--{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                <span class="step__dot">
                    @if ($step['state'] === 'done')
                        <x-icon name="check" />
                    @elseif ($step['state'] === 'stopped')
                        !
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>
                <span class="step__label">{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>
    <p class="stepper__summary" aria-hidden="true">Step {{ $focusIndex + 1 }} of {{ count($steps) }}: {{ $steps[$focusIndex]['label'] }}</p>
</div>
