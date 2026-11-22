// Pause-aware chunking for live consultation audio — Node port of indic-flex-runpod-worker/test_app/vad_chunker.py
// (Silero VAD v5 ONNX). Same rules, same numbers, so cuts match the Python version that was tested on 10 recordings:
//   - never cut before MIN_S (45 s) of the current chunk
//   - from TARGET_S (60 s) on: cut in the first pause >= 0.3 s
//   - between MIN_S and TARGET_S: cut only in a long pause >= 0.8 s
//   - no usable pause by MAX_S (90 s): cut at the quietest 0.3 s between MIN_S and MAX_S
//   - the cut goes inside the pause (up to 1 s after it starts), so no word is split
//   - live: a cut is final once SETTLE_S (2 s) of later audio follow it (quietest-point cuts: at MAX_S + 2 s)
import * as ort from "onnxruntime-node";

export const SR = 16000;
export const FRAME = 512;                 // Silero frame at 16 kHz = 32 ms
export const FRAME_S = FRAME / SR;

export const DEFAULT_CONFIG = {
  minS: 45, targetS: 60, maxS: 90,
  minPauseS: 0.3, longPauseS: 0.8,
  threshold: 0.5, negThreshold: 0.35,    // speech starts at >= 0.5, ends below 0.35 (hysteresis, as in Silero)
};

export class SileroVAD {
  static async load(path) {
    const v = new SileroVAD();
    v.sess = await ort.InferenceSession.create(path, { intraOpNumThreads: 1, interOpNumThreads: 1 });
    [v.outProb, v.outState] = v.sess.outputNames;
    return v;
  }
}

/** Recurrent state, 64-sample context and any partial frame are kept between feed() calls. */
export class StreamingVAD {
  constructor(vad) {
    this.vad = vad;
    this.state = new Float32Array(2 * 1 * 128);
    this.context = new Float32Array(64);
    this.rest = new Float32Array(0);
    this.sr = new ort.Tensor("int64", BigInt64Array.from([BigInt(SR)]), []);
  }

  async feed(x) {
    const all = concat(this.rest, x);
    const n = Math.floor(all.length / FRAME);
    const out = new Float32Array(n);
    for (let k = 0; k < n; k++) {
      const inp = new Float32Array(64 + FRAME);
      inp.set(this.context, 0);
      inp.set(all.subarray(k * FRAME, (k + 1) * FRAME), 64);
      const r = await this.vad.sess.run({
        input: new ort.Tensor("float32", inp, [1, 64 + FRAME]),
        state: new ort.Tensor("float32", this.state, [2, 1, 128]),
        sr: this.sr,
      });
      this.state = Float32Array.from(r[this.vad.outState].data);
      this.context = inp.slice(inp.length - 64);
      out[k] = r[this.vad.outProb].data[0];
    }
    this.rest = all.slice(n * FRAME);
    return out;
  }
}

export function speechFlags(probs, cfg) {
  const flags = new Uint8Array(probs.length);
  let on = false;
  for (let k = 0; k < probs.length; k++) {
    on = !on ? probs[k] >= cfg.threshold : probs[k] >= cfg.negThreshold;
    flags[k] = on ? 1 : 0;
  }
  return flags;
}

/** [start_s, end_s] of every run of non-speech frames. */
export function pauses(flags) {
  const out = [];
  let k = 0;
  const n = flags.length;
  while (k < n) {
    if (flags[k]) { k++; continue; }
    let j = k;
    while (j < n && !flags[j]) j++;
    out.push([k * FRAME_S, j * FRAME_S]);
    k = j;
  }
  return out;
}

function longestRun(flags, a, b) {
  let best = 0, cur = 0;
  for (let k = a; k < b; k++) { cur = flags[k] ? cur + 1 : 0; if (cur > best) best = cur; }
  return best;
}

function quietestPoint(wav, a, b, winS = 0.3, hopS = 0.05) {
  const lo = Math.floor(a * SR), hi = Math.floor(b * SR);
  const win = Math.floor(winS * SR), hop = Math.floor(hopS * SR);
  const len = Math.min(hi, wav.length) - lo;
  if (len <= win) return (a + b) / 2;
  let bestI = 0, bestE = Infinity, idx = 0;
  for (let i = 0; i < len - win; i += hop, idx++) {
    let e = 0;
    for (let s = lo + i; s < lo + i + win; s++) e += wav[s] * wav[s];
    e /= win;
    if (e < bestE) { bestE = e; bestI = idx; }
  }
  return (lo + bestI * hop + Math.floor(win / 2)) / SR;
}

/** Plan chunks over a buffer (times relative to the buffer start). Same algorithm as plan_chunks() in Python. */
export function planChunks(wav, probs, cfg = DEFAULT_CONFIG) {
  const total = wav.length / SR;
  const flags = speechFlags(probs, cfg);
  const ps = pauses(flags);
  const chunks = [];
  let start = 0;
  while (total - start > 0.05) {
    let cut = null, reason = null;
    for (const [pStart, pEnd] of ps) {
      if (pEnd <= start) continue;
      const s = Math.max(pStart, start);
      const dur = pEnd - s + 1e-6;
      if (dur < cfg.minPauseS) continue;
      let c = s + Math.min(dur / 2, 1.0);
      if (c < start + cfg.minS) {
        const tgt = start + cfg.targetS;
        c = pEnd - tgt >= 0.15 ? tgt : start + cfg.minS;
        if (pEnd - c < 0.15) continue;
      }
      const elapsed = c - start;
      if (elapsed > cfg.maxS || c >= total) break;
      if (elapsed < cfg.minS) continue;
      if (elapsed >= cfg.targetS) { cut = c; reason = "pause"; break; }
      if (dur >= cfg.longPauseS) { cut = c; reason = "long-pause"; break; }
    }
    if (cut === null) {
      if (total - start <= cfg.maxS) { cut = total; reason = "end"; }
      else { cut = quietestPoint(wav, start + cfg.minS, start + cfg.maxS); reason = "quietest-point"; }
    }
    const a = Math.floor(start / FRAME_S), b = Math.max(a + 1, Math.floor(cut / FRAME_S));
    let sum = 0;
    for (let k = a; k < Math.min(b, flags.length); k++) sum += flags[k];
    chunks.push({
      index: chunks.length, start: round(start, 3), end: round(cut, 3), reason,
      speechRatio: b > a ? round(sum / (b - a), 3) : 0,
      longestSpeechS: round(longestRun(flags, a, Math.min(b, flags.length)) * FRAME_S, 2),
    });
    start = cut;
  }
  return chunks;
}

/**
 * plan_chunks applied to a live 16 kHz stream; only the current (not yet cut) chunk is kept in memory.
 *
 * Robustness options (opts):
 *   dropSilence (true)   A: while the open chunk holds no speech at all, discard its audio except the last keepS
 *                           seconds (so the start of the next word is kept) and remember the silent span instead of
 *                           cutting empty 45-s chunks. Spans >= silenceReportS are reported via takeSilences().
 *   keepS (1.0)             seconds of silence kept in front of speech
 *   silenceReportS (5)      shortest silent span worth reporting
 *   trim (true)          E: leading/trailing silence longer than trimMinS inside a cut chunk is trimmed from the audio
 *                           handed out, keeping trimPadS around the speech. Chunk start/end stay the real stream times;
 *                           speechStart/speechEnd say what the trimmed audio covers.
 *   trimMinS (1.0), trimPadS (0.3)
 * Times are seconds since the stream started; lastSpeechS = end of the most recent speech frame (for idle detection).
 * With dropSilence=false and trim=false the cuts are identical to the Python LiveChunker (parity test).
 */
export class LiveChunker {
  constructor(vad, cfg = DEFAULT_CONFIG, settleS = 2.0, opts = {}) {
    this.cfg = cfg;
    this.settleS = settleS;
    this.opts = { dropSilence: true, keepS: 1.0, silenceReportS: 5, trim: true, trimMinS: 1.0, trimPadS: 0.3, ...opts };
    this.vad = new StreamingVAD(vad);
    this.buf = new Float32Array(0);
    this.probs = new Float32Array(0);
    this.offset = 0;              // samples before the buffer start (handed out as chunks or dropped as silence)
    this.count = 0;
    this.lastSpeechS = 0;         // stream time of the last speech frame seen
    this.everSpoke = false;
    this.silenceStart = null;     // stream time where the current dropped-silence span began
    this.silences = [];           // finished spans [{start, end}] not yet taken by the caller
    this.droppedS = 0;
  }

  get elapsedS() { return (this.offset + this.buf.length) / SR; }
  get chunkS() { return this.buf.length / SR; }
  speaking() {
    const p = this.probs;
    for (let k = Math.max(0, p.length - 3); k < p.length; k++) if (p[k] >= this.cfg.threshold) return true;
    return false;
  }

  /** Silent spans reported since the last call: [{start, end, durationS}] (stream seconds). */
  takeSilences() { const s = this.silences; this.silences = []; return s; }

  hasSpeech(from = 0, to = this.probs.length) {
    for (let k = from; k < to; k++) if (this.probs[k] >= this.cfg.threshold) return true;
    return false;
  }

  /** Add audio (Float32 16 kHz mono); returns chunks that became final: [{chunk, audio}]. */
  async feed(pcm) {
    if (!pcm || !pcm.length) return [];
    const before = this.probs.length;
    this.buf = concat(this.buf, pcm);
    this.probs = concat(this.probs, await this.vad.feed(pcm));
    // idle detection counts only real speech: a run of >= minSpeechRunS (0.25 s) above threshold, not noise blips
    const runNeeded = Math.max(1, Math.round((this.opts.minSpeechRunS ?? 0.25) / FRAME_S));
    for (let k = before; k < this.probs.length; k++) {
      this.run = this.probs[k] >= this.cfg.threshold ? (this.run || 0) + 1 : 0;
      if (this.run >= runNeeded) {
        this.lastSpeechS = this.offset / SR + (k + 1) * FRAME_S;
        this.everSpoke = true;
      }
    }
    if (this.opts.dropSilence) this.dropSilence();
    const out = [];
    while (this.chunkS >= this.cfg.minS) {
      const first = planChunks(this.buf, this.probs, this.cfg)[0];
      const n = this.chunkS;
      const ready = ((first.reason === "pause" || first.reason === "long-pause") && first.end <= n - this.settleS)
        || (first.reason === "quietest-point" && n >= this.cfg.maxS + this.settleS);
      if (!ready) break;
      out.push(this.cut(first));
      if (this.opts.dropSilence) this.dropSilence();
    }
    return out;
  }

  /** A: the open chunk has no speech yet -> keep only its last keepS seconds. */
  dropSilence() {
    if (this.hasSpeech()) {                                 // speech (re)started: close the silent span
      this.closeSilence(this.offset / SR);
      return;
    }
    const keep = Math.round(this.opts.keepS / FRAME_S);
    const drop = this.probs.length - keep;
    if (drop <= 0) return;
    if (this.silenceStart === null) this.silenceStart = this.offset / SR;
    const n = drop * FRAME;
    this.buf = this.buf.slice(n);
    this.probs = this.probs.slice(drop);
    this.offset += n;
    this.droppedS += n / SR;
  }

  closeSilence(end) {
    if (this.silenceStart === null) return;
    const d = end - this.silenceStart;
    if (d >= this.opts.silenceReportS) this.silences.push({ start: round(this.silenceStart, 2), end: round(end, 2), durationS: round(d, 1) });
    this.silenceStart = null;
  }

  /** Recording stopped: whatever is left becomes the last chunk(s); a speech-free remainder is dropped. */
  async finish() {
    if (this.vad.rest.length) {
      const pad = (FRAME - (this.vad.rest.length % FRAME)) % FRAME;
      this.probs = concat(this.probs, await this.vad.feed(new Float32Array(pad)));
    }
    const out = [];
    if (this.opts.dropSilence && !this.hasSpeech()) {        // nothing said since the last cut
      if (this.silenceStart === null && this.chunkS > 0) this.silenceStart = this.offset / SR;
      this.offset += this.buf.length;
      this.droppedS += this.buf.length / SR;
      this.buf = new Float32Array(0);
      this.probs = new Float32Array(0);
      this.closeSilence(this.offset / SR);
      return out;
    }
    this.closeSilence(this.offset / SR);
    while (this.chunkS > 0.05) out.push(this.cut(planChunks(this.buf, this.probs, this.cfg)[0]));
    return out;
  }

  cut(ch) {
    let n, frames;
    if (ch.reason === "end") { n = this.buf.length; frames = this.probs.length; }
    else { frames = Math.max(1, Math.round(ch.end / FRAME_S)); n = Math.min(this.buf.length, frames * FRAME); }
    // E: trim long leading / trailing silence from the audio handed out (times stay the real stream times)
    let a = 0, b = n;
    const flags = speechFlags(this.probs.subarray(0, Math.min(frames, this.probs.length)), this.cfg);
    let fs = -1, ls = -1;
    for (let k = 0; k < flags.length; k++) if (flags[k]) { if (fs < 0) fs = k; ls = k; }
    const pad = Math.round(this.opts.trimPadS / FRAME_S);
    if (this.opts.trim && fs >= 0) {
      if (fs * FRAME_S > this.opts.trimMinS) a = Math.max(0, fs - pad) * FRAME;
      const tailS = n / SR - (ls + 1) * FRAME_S;
      if (tailS > this.opts.trimMinS) b = Math.min(n, (ls + 1 + pad) * FRAME);
    }
    const audio = this.buf.slice(a, b);
    this.buf = this.buf.slice(n);
    this.probs = this.probs.slice(frames);
    const start = this.offset / SR;
    this.offset += n;
    const chunk = {
      index: this.count++, start: round(start, 3), end: round(this.offset / SR, 3), reason: ch.reason,
      speechRatio: ch.speechRatio, longestSpeechS: ch.longestSpeechS,
      speechStart: round(start + a / SR, 3), speechEnd: round(start + b / SR, 3),
      trimmedS: round((n - (b - a)) / SR, 2),
    };
    return { chunk, audio };
  }
}

function concat(a, b) {
  const out = new Float32Array(a.length + b.length);
  out.set(a, 0);
  out.set(b, a.length);
  return out;
}

function round(x, d) { const f = 10 ** d; return Math.round(x * f) / f; }
