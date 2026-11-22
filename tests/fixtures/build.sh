#!/usr/bin/env bash
# Decode the committed FLAC fixtures to 16 kHz mono PCM16 WAV (FLAC is lossless, so the samples are exact).
# Optional: put your own recordings (any format) in tests/fixtures/private/ — they are converted too, never committed.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p build
for f in src/*.flac; do
  ffmpeg -v error -y -i "$f" -ar 16000 -ac 1 -c:a pcm_s16le "build/$(basename "${f%.*}").wav"
done
shopt -s nullglob
for f in private/*.{wav,webm,mp3,m4a,ogg,flac}; do
  out="build/private-$(basename "${f%.*}").wav"
  [ -f "$out" ] || ffmpeg -v error -y -i "$f" -ar 16000 -ac 1 -c:a pcm_s16le "$out"
done
ls -1 build
