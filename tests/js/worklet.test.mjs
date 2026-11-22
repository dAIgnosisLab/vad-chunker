// Checks the AudioWorklet's conversion to 16 kHz PCM16 (incl. the resampling path Firefox uses) in Node.
//   node tests/js/worklet.test.mjs
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const src = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/emr-voice-worklet.js'), 'utf8');

function run(rate, signal, seconds) {
  let Proc;
  const ctx = { sampleRate: rate, AudioWorkletProcessor: class { constructor() { this.port = { postMessage: (m) => frames.push(m), onmessage: null }; } }, registerProcessor: (_, c) => { Proc = c; }, Math };
  const frames = [];
  vm.runInNewContext(src, ctx);
  const p = new Proc({ processorOptions: { frameMs: 100 } });
  const total = Math.round(rate * seconds);
  for (let i = 0; i < total; i += 128) {
    const block = new Float32Array(Math.min(128, total - i));
    for (let k = 0; k < block.length; k++) block[k] = signal((i + k) / rate);
    p.process([[block]]);
  }
  p.port.onmessage({ data: 'flush' });
  const pcm = frames.filter((f) => f.pcm).map((f) => new Int16Array(f.pcm));
  const out = new Int16Array(pcm.reduce((a, f) => a + f.length, 0));
  let o = 0;
  for (const f of pcm) { out.set(f, o); o += f.length; }
  return { out, frames: pcm };
}

// amplitude of frequency f in x (16 kHz), by correlation over the steady part
function amp(x, f) {
  let re = 0, im = 0;
  const from = 1600, n = x.length - from;
  for (let i = from; i < x.length; i++) {
    const t = i / 16000, v = x[i] / 32768;
    re += v * Math.cos(2 * Math.PI * f * t);
    im += v * Math.sin(2 * Math.PI * f * t);
  }
  return (2 * Math.sqrt(re * re + im * im)) / n;
}

for (const rate of [16000, 44100, 48000]) {
  const { out, frames } = run(rate, (t) => 0.5 * Math.sin(2 * Math.PI * 1000 * t), 2);
  assert.ok(Math.abs(out.length - 32000) <= 2, `${rate} Hz in → 2 s = 32000 samples out (got ${out.length})`);
  assert.ok(frames.slice(0, -1).every((f) => f.length === 1600), 'full frames are 100 ms');
  const a = amp(out, 1000);
  assert.ok(a > 0.47 && a < 0.53, `${rate} Hz: 1 kHz tone keeps its level (${a.toFixed(3)})`);
  if (rate !== 16000) {
    const { out: hi } = run(rate, (t) => 0.5 * Math.sin(2 * Math.PI * 11000 * t), 2);
    const alias = amp(hi, 16000 - 11000);   // an unfiltered 11 kHz tone would fold to 5 kHz
    assert.ok(alias < 0.005, `${rate} Hz: 11 kHz is filtered, not aliased to 5 kHz (${alias.toFixed(4)})`);
  }
  console.log(`ok  - ${rate} Hz input`);
}
const { out } = run(16000, () => 1.5, 0.2);
assert.ok(out.every((v) => v === 32767), 'clipping saturates');
console.log('all worklet tests passed');
