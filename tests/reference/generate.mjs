// Produces tests/reference/*.json: what the production LiveChunker (vad.js, copied verbatim from
// EMR_websocket/src/audio/vad.js) emits for each fixture when fed in seeded random piece sizes.
// The PHP port is tested against these files (tests/ParityTest.php).
//
//   cd tests/reference && npm install --ignore-scripts && node generate.mjs [fixture.wav ...]
import fs from "fs";
import path from "path";
import crypto from "crypto";
import { fileURLToPath } from "url";
import { SileroVAD, LiveChunker, StreamingVAD, SR } from "./vad.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const buildDir = path.join(here, "..", "fixtures", "build");
const model = path.join(here, "..", "..", "resources", "models", "silero_vad.onnx");

function readWav(file) {
  const b = fs.readFileSync(file);
  let p = 12;
  while (p + 8 <= b.length) {
    const id = b.toString("ascii", p, p + 4), size = b.readUInt32LE(p + 4);
    if (id === "data") return b.subarray(p + 8, p + 8 + size);
    p += 8 + size + (size % 2);
  }
  throw new Error(`no data chunk in ${file}`);
}

function toFloat(pcm) {
  const out = new Float32Array(pcm.length >> 1);
  for (let i = 0; i < out.length; i++) out[i] = pcm.readInt16LE(i * 2) / 32768;
  return out;
}

function sha1Int16(audio) {
  const b = Buffer.allocUnsafe(audio.length * 2);
  for (let i = 0; i < audio.length; i++) b.writeInt16LE(Math.round(audio[i] * 32768), i * 2);
  return crypto.createHash("sha1").update(b).digest("hex");
}

function mulberry32(a) {
  return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

// Extra runs with tightened rules, to exercise paths the default 45/60/90 s rules rarely hit on real speech
// (the quietest-point fallback needs 90 s without a 0.3 s pause).
const VARIANTS = {
  overlap: [["overlap@tight", { minS: 10, targetS: 12, maxS: 14, minPauseS: 1.5, longPauseS: 2.0, threshold: 0.5, negThreshold: 0.35 }]],
  gaps: [["gaps@short", { minS: 8, targetS: 10, maxS: 15, minPauseS: 0.3, longPauseS: 0.8, threshold: 0.5, negThreshold: 0.35 }]],
};

const vad = await SileroVAD.load(model);
const files = process.argv.length > 2 ? process.argv.slice(2) : fs.readdirSync(buildDir).filter((f) => f.endsWith(".wav")).map((f) => path.join(buildDir, f));
const runs = files.flatMap((file) => {
  const base = path.basename(file, ".wav");
  return [[base, file, undefined], ...(VARIANTS[base] || []).map(([name, cfg]) => [name, file, cfg])];
});

for (const [name, file, cfg] of runs) {
  const audio = toFloat(readWav(file));
  const seed = [...name].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7);
  const rnd = mulberry32(seed);

  const pieces = [];
  for (let pos = 0; pos < audio.length;) {
    const r = rnd();
    const n = Math.min(audio.length - pos, r < 0.1 ? 1 + Math.floor(rnd() * 700) : 1600 + Math.floor(rnd() * 94400));
    pieces.push(n);
    pos += n;
  }

  const chunker = cfg ? new LiveChunker(vad, cfg) : new LiveChunker(vad);
  const chunks = [], silences = [];
  const emit = (cuts, at) => {
    for (const { chunk, audio: a } of cuts) chunks.push({ ...chunk, samples: a.length, sha1: sha1Int16(a), emittedAfterPiece: at });
    silences.push(...chunker.takeSilences());
  };
  const t0 = Date.now();
  let pos = 0;
  for (let i = 0; i < pieces.length; i++) {
    emit(await chunker.feed(audio.subarray(pos, pos + pieces[i])), i);
    pos += pieces[i];
  }
  emit(await chunker.finish(), "finish");

  // raw probabilities over the first 60 s, to compare the model output itself
  const sv = new StreamingVAD(vad);
  const probs = Array.from(await sv.feed(audio.subarray(0, Math.min(audio.length, 60 * SR))), (p) => Math.round(p * 1e6) / 1e6);

  const ref = { fixture: path.basename(file), config: cfg ?? null, samples: audio.length, seed, pieces, chunks, silences,
    droppedS: Math.round(chunker.droppedS * 1000) / 1000, probs60s: probs };
  const outDir = name.startsWith("private-") ? path.join(here, "private") : here;   // never committed
  fs.mkdirSync(outDir, { recursive: true });
  fs.writeFileSync(path.join(outDir, `${name}.json`), JSON.stringify(ref));
  console.log(`${name}: ${(audio.length / SR).toFixed(1)} s, ${pieces.length} pieces, ${chunks.length} chunks, ${silences.length} silences (${Date.now() - t0} ms)`);
  for (const c of chunks) console.log(`  #${c.index} ${c.start}-${c.end} ${c.reason} ratio=${c.speechRatio} trimmed=${c.trimmedS}`);
}
