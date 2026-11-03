"""Expands the recorded frames into a numbered 30 fps sequence of symlinks for one language."""
import json
import os
import shutil
import sys

work, language = sys.argv[1], sys.argv[2]
target = os.path.join(work, 'sequence', language)
shutil.rmtree(target, ignore_errors=True)
os.makedirs(target)
number = 0
for frame in json.load(open(os.path.join(work, 'frames.json'))):
    source = os.path.join(work, 'frames', language, frame['file'])
    for _ in range(frame['count']):
        number += 1
        os.symlink(source, os.path.join(target, f'{number:06d}.jpg'))
print(f'{language}: {number} video frames, {number / 30:.2f} s')
