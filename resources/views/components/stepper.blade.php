@props(['steps'])
<ol class="stepper" aria-label="Progress">
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
            {{ $step['label'] }}
        </li>
    @endforeach
</ol>
