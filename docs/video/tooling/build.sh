#!/usr/bin/env bash
#
# Builds the workflow videos in docs/video from the running local app:
#   xfunding-hub-full-workflow.en.mp4  English captions and English voice, no Sinhala text.
#   xfunding-hub-full-workflow.si.mp4  Sinhala captions, no voice.
#
# Usage: docs/video/tooling/build.sh [--preview]
#   --preview stops before saving a request, so it adds no deal, and leaves its videos in the work folder.
#
# Needs a local site at VIDEO_BASE_URL (default http://xfunding-hub.test) with demo payments enabled and
# DemoMarketplaceSeeder run, plus google-chrome, node 22, python3, curl, php, and GStreamer with
# x264enc, mp4mux, avenc_aac and avdec_aac. Each full build adds one synthetic settled deal to the local database.
#
# Captions live in script.en.json and script.si.json. The English voice is Piper with en_GB-cori-medium,
# whose model card lists a public domain dataset and training from scratch. Both are downloaded to the cache
# folder and are not part of this repository. Optional folders: VIDEO_CACHE_DIR and VIDEO_WORK_DIR.
set -euo pipefail

TOOLING=$(cd "$(dirname "$0")" && pwd)
OUTPUT=$(cd "$TOOLING/.." && pwd)
CACHE=${VIDEO_CACHE_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/xfunding-video}
WORK=${VIDEO_WORK_DIR:-$(mktemp -d)}
VOICE=en_GB-cori-medium
VOICE_PATH=en/en_GB/cori/medium
VOICE_BASE=https://huggingface.co/rhasspy/piper-voices/resolve/main
PIPER_RELEASE=https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_x86_64.tar.gz
# GitHub publishes no checksum for this release, so this pins the file used to build the committed videos.
PIPER_SHA256=a50cb45f355b7af1f6d758c1b360717877ba0a398cc8cbe6d2a7a3a26e225992
PREVIEW=0
[ "${1:-}" = "--preview" ] && PREVIEW=1
mkdir -p "$CACHE/voice" "$WORK"

if [ ! -x "$CACHE/piper/piper" ]; then
    curl -sSL -f -o "$CACHE/piper.tar.gz" "$PIPER_RELEASE"
    echo "$PIPER_SHA256  $CACHE/piper.tar.gz" | sha256sum -c --quiet -
    tar -xzf "$CACHE/piper.tar.gz" -C "$CACHE"
    rm "$CACHE/piper.tar.gz"
fi
if [ ! -f "$CACHE/voice/$VOICE.onnx" ]; then
    curl -sSL -f -o "$CACHE/voice/voices.json" "$VOICE_BASE/voices.json"
    curl -sSL -f -o "$CACHE/voice/$VOICE.onnx.partial" "$VOICE_BASE/$VOICE_PATH/$VOICE.onnx"
    curl -sSL -f -o "$CACHE/voice/$VOICE.onnx.json" "$VOICE_BASE/$VOICE_PATH/$VOICE.onnx.json"
    python3 - "$CACHE/voice" "$VOICE" <<'PY'
import hashlib, json, os, sys
folder, voice = sys.argv[1], sys.argv[2]
files = json.load(open(os.path.join(folder, 'voices.json')))[voice]['files']
meta = next(value for path, value in files.items() if path.endswith(f'{voice}.onnx'))
digest = hashlib.md5(open(os.path.join(folder, f'{voice}.onnx.partial'), 'rb').read()).hexdigest()
sys.exit(0 if digest == meta['md5_digest'] else f'Checksum mismatch for {voice}')
PY
    mv "$CACHE/voice/$VOICE.onnx.partial" "$CACHE/voice/$VOICE.onnx"
fi

export VIDEO_WORK_DIR=$WORK VIDEO_PREVIEW=$PREVIEW VIDEO_AUDIO_CACHE=$CACHE/audio/$VOICE
export PIPER_BIN=$CACHE/piper/piper PIPER_MODEL=$CACHE/voice/$VOICE.onnx
node "$TOOLING/film.mjs"
python3 "$TOOLING/mix.py" "$WORK"

encode() {
    local language=$1 target=$2
    python3 "$TOOLING/sequence.py" "$WORK" "$language"
    local video=(multifilesrc "location=$WORK/sequence/$language/%06d.jpg" index=1 'caps=image/jpeg,framerate=(fraction)30/1' ! jpegdec ! videoconvert ! video/x-raw,format=I420,colorimetry=bt709
        ! x264enc pass=qual quantizer=22 speed-preset=medium key-int-max=60 ! video/x-h264,profile=high,stream-format=avc,alignment=au
        ! queue max-size-time=0 max-size-buffers=0 max-size-bytes=0 ! mux.)
    local audio=()
    if [ "$language" = "en" ]; then
        audio=(filesrc "location=$WORK/narration.wav" ! wavparse ! audioconvert ! audioresample ! audio/x-raw,rate=44100,channels=1 ! avenc_aac bitrate=128000
            ! queue max-size-time=0 max-size-buffers=0 max-size-bytes=0 ! mux.)
    fi
    gst-launch-1.0 -q -e mp4mux name=mux faststart=true ! filesink "location=$target" "${video[@]}" "${audio[@]}"
    rm -rf "$WORK/sequence/$language"
}

if [ "$PREVIEW" = "1" ]; then
    EN=$WORK/preview.en.mp4
    SI=$WORK/preview.si.mp4
else
    EN=$OUTPUT/xfunding-hub-full-workflow.en.mp4
    SI=$OUTPUT/xfunding-hub-full-workflow.si.mp4
fi
encode en "$EN"
encode si "$SI"
python3 "$TOOLING/avcheck.py" "$EN" "$WORK"
node "$TOOLING/verify.mjs" "$EN" "$WORK" audio ${VIDEO_SAMPLE_TIMES:-}
node "$TOOLING/verify.mjs" "$SI" "$WORK" silent ${VIDEO_SAMPLE_TIMES:-}
ls -la "$EN" "$SI"
