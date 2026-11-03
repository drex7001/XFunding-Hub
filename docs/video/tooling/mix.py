"""Places every voice cue at its recorded time and writes one narration track."""
import array
import json
import os
import sys
import wave

work = sys.argv[1]
spec = json.load(open(os.path.join(work, 'cues.json')))
RATE = 22050
total = int(spec['seconds'] * RATE) + 1
mix = array.array('h', bytes(2 * total))
previous_end = 0
for cue in spec['cues']:
    with wave.open(cue['file']) as source:
        assert source.getframerate() == RATE and source.getnchannels() == 1 and source.getsampwidth() == 2
        data = array.array('h', source.readframes(source.getnframes()))
    start = int(cue['at'] * RATE)
    assert start >= previous_end, f"voice overlap at {cue['at']}: {cue['text'][:40]}"
    assert start + len(data) <= total, f"voice runs past the end: {cue['text'][:40]}"
    mix[start:start + len(data)] = data
    previous_end = start + len(data)
peak = max(abs(value) for value in mix) or 1
gain = min(1.6, 0.8 * 32767 / peak)
mix = array.array('h', (int(value * gain) for value in mix))
with wave.open(os.path.join(work, 'narration.wav'), 'wb') as target:
    target.setnchannels(1)
    target.setsampwidth(2)
    target.setframerate(RATE)
    target.writeframes(mix.tobytes())
speech = sum(cue['duration'] for cue in spec['cues'])
print(f"narration: {len(spec['cues'])} cues, {speech / 60:.2f} min of speech in {spec['seconds'] / 60:.2f} min")
