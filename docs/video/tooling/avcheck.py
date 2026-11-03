"""Decodes the audio back out of the MP4 and confirms it lines up with the narration track."""
import array
import json
import math
import os
import subprocess
import sys
import wave

video, work = sys.argv[1], sys.argv[2]
narration = os.path.join(work, 'narration.wav')
decoded = os.path.join(work, 'decoded.wav')
subprocess.run(['gst-launch-1.0', '-q', '-e', 'filesrc', f'location={video}', '!', 'qtdemux', 'name=d', 'd.audio_0', '!', 'avdec_aac', '!', 'audioconvert', '!', 'audioresample', '!', 'audio/x-raw,format=S16LE,rate=22050,channels=1', '!', 'wavenc', '!', 'filesink', f'location={decoded}'], check=True)


def envelope(path, window=2205):
    with wave.open(path) as source:
        data = array.array('h', source.readframes(source.getnframes()))
    return [math.sqrt(sum(value * value for value in data[i:i + window]) / window) for i in range(0, len(data) - window, window)]


def correlation(x, y):
    n = min(len(x), len(y))
    x, y = x[:n], y[:n]
    mean_x, mean_y = sum(x) / n, sum(y) / n
    top = sum((p - mean_x) * (q - mean_y) for p, q in zip(x, y))
    bottom = math.sqrt(sum((p - mean_x) ** 2 for p in x) * sum((q - mean_y) ** 2 for q in y)) or 1
    return top / bottom


source, result = envelope(narration), envelope(decoded)
best = max(range(-5, 6), key=lambda lag: correlation(source[max(0, -lag):], result[max(0, lag):]))
cues = json.load(open(os.path.join(work, 'cues.json')))['cues']
audible = sum(max(result[int((cue['at'] + cue['duration'] / 2) * 10) - 3:int((cue['at'] + cue['duration'] / 2) * 10) + 4]) >= 200 for cue in cues)
print(f'audio: {len(result) * 0.1:.1f} s, offset {best * 100} ms, correlation {correlation(source[max(0, -best):], result[max(0, best):]):.4f}, audible cues {audible} of {len(cues)}')
os.remove(decoded)
if best != 0 or audible != len(cues):
    sys.exit('The audio does not line up with the narration.')
