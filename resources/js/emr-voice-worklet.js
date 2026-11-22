// AudioWorklet for emr-voice-recorder.js: mono mic audio → 16 kHz PCM16 frames (default 100 ms), posted as
// transferable ArrayBuffers together with the frame's RMS level. If the AudioContext already runs at 16 kHz
// (Chrome, Edge, Safari) samples are only converted; otherwise (Firefox) they are low-pass filtered and
// resampled here.
const OUT_RATE = 16000;

class EmrVoicePcm extends AudioWorkletProcessor {
  constructor(options) {
    super();
    const frameMs = (options.processorOptions && options.processorOptions.frameMs) || 100;
    this.frameLen = Math.round(OUT_RATE * frameMs / 1000);
    this.ratio = sampleRate / OUT_RATE;                 // input samples per output sample
    this.out = new Int16Array(this.frameLen);
    this.n = 0;
    this.sumSq = 0;
    this.stopped = false;
    if (this.ratio !== 1) {
      // windowed-sinc low-pass at 7.2 kHz (below the 8 kHz Nyquist of the output), 63 taps
      const taps = 63, fc = 7200 / sampleRate, mid = (taps - 1) / 2;
      this.fir = new Float32Array(taps);
      let sum = 0;
      for (let i = 0; i < taps; i++) {
        const x = i - mid;
        const sinc = x === 0 ? 2 * fc : Math.sin(2 * Math.PI * fc * x) / (Math.PI * x);
        const w = 0.42 - 0.5 * Math.cos((2 * Math.PI * i) / (taps - 1)) + 0.08 * Math.cos((4 * Math.PI * i) / (taps - 1));
        this.fir[i] = sinc * w;
        sum += this.fir[i];
      }
      for (let i = 0; i < taps; i++) this.fir[i] /= sum;
      this.hist = new Float32Array(taps);               // ring buffer of recent input samples
      this.hpos = 0;
      this.filtered = [];                                // filtered input not yet consumed by the resampler
      this.t = 0;                                        // fractional read position into `filtered`
    }
    this.port.onmessage = (e) => {
      if (e.data === 'flush') {
        this.emit(true);
        this.stopped = true;
        this.port.postMessage({ flushed: true });
      }
    };
  }

  push(s) {
    s = Math.max(-1, Math.min(1, s));
    this.out[this.n++] = s < 0 ? Math.round(s * 32768) : Math.round(s * 32767);
    this.sumSq += s * s;
    if (this.n === this.frameLen) this.emit(false);
  }

  emit(partial) {
    if (this.n === 0) return;
    const frame = partial ? this.out.slice(0, this.n) : this.out;
    const rms = Math.sqrt(this.sumSq / this.n);
    const buf = frame.buffer.slice(0, this.n * 2);
    this.port.postMessage({ pcm: buf, rms }, [buf]);
    this.n = 0;
    this.sumSq = 0;
  }

  process(inputs) {
    if (this.stopped) return false;
    const input = inputs[0];
    if (!input || input.length === 0) return true;
    const ch = input[0];
    if (this.ratio === 1) {
      for (let i = 0; i < ch.length; i++) this.push(ch[i]);
      return true;
    }
    const fir = this.fir, taps = fir.length, hist = this.hist;
    for (let i = 0; i < ch.length; i++) {
      hist[this.hpos] = ch[i];
      this.hpos = (this.hpos + 1) % taps;
      let acc = 0;
      for (let k = 0, p = this.hpos; k < taps; k++, p = (p + 1) % taps) acc += fir[k] * hist[p];
      this.filtered.push(acc);
    }
    const f = this.filtered;
    while (this.t + 1 < f.length) {
      const i = Math.floor(this.t), frac = this.t - i;
      this.push(f[i] + (f[i + 1] - f[i]) * frac);
      this.t += this.ratio;
    }
    // keep the read position across blocks: it may point past the end of this block (carried into the next)
    const drop = Math.min(Math.floor(this.t), f.length);
    if (drop > 0) {
      this.filtered = f.slice(drop);
      this.t -= drop;
    }
    return true;
  }
}

registerProcessor('emr-voice-pcm', EmrVoicePcm);
