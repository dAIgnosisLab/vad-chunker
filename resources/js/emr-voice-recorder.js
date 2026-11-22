/*
 * emr-voice browser recorder. Captures the microphone as 16 kHz mono PCM16 and streams it to the
 * emr-voice WebSocket server (php artisan emr-voice:serve), which cuts it at speech pauses and sends it to
 * the EMR pipeline. Survives network drops and server restarts: audio is kept until the server acks it
 * and is resent after reconnecting.
 *
 *   <script src="/vendor/emr-voice/emr-voice-recorder.js"></script>
 *   const rec = await EmrVoiceRecorder.start({
 *     url: 'wss://example.org/vad',
 *     getToken: async () => (await fetch('/consultations/42/recording-token', { method: 'POST' })).json().then(r => r.token),
 *     onStateChange: (s) => …,  onChunk: (c) => …,  onDone: (d) => …,  onError: (e) => …,  onLevel: (rms) => …,
 *   });
 *   …  await rec.stop();      // or rec.cancel()
 *
 * EmrVoiceStream (below) is the transport alone — feed it PCM16 yourself if you capture audio another way.
 */
(function (global) {
  'use strict';

  var SAMPLE_RATE = 16000;
  var BYTES_PER_SECOND = SAMPLE_RATE * 2;
  var RECONNECT_DELAYS_MS = [250, 500, 1000, 2000, 4000, 8000];
  var NO_RECONNECT_CODES = { 4000: 'replaced by a newer connection' };

  function noop() {}

  /**
   * Transport: ordered PCM16 stream over a WebSocket with acks, reconnect and resend.
   * options: url, token | getToken(), WebSocket (constructor, default global), maxBufferSeconds (600),
   *          onStateChange, onReady, onAck, onChunk, onSilence, onFinishing, onDone, onError
   */
  function EmrVoiceStream(options) {
    this.o = options;
    this.WS = options.WebSocket || global.WebSocket;
    this.maxBufferBytes = (options.maxBufferSeconds || 600) * BYTES_PER_SECOND;
    this.frames = [];          // [{offset, data: Uint8Array}] not yet acked, in stream order
    this.written = 0;          // bytes of stream produced so far
    this.acked = 0;
    this.sentUpTo = 0;         // bytes sent on the current connection
    this.ws = null;
    this.ready = false;
    this.state = 'idle';
    this.stopRequested = false;
    this.cancelRequested = false;
    this.attempt = 0;
    this.finished = false;
    var self = this;
    this.donePromise = new Promise(function (resolve, reject) { self._resolve = resolve; self._reject = reject; });
  }

  EmrVoiceStream.prototype._emit = function (name, arg) {
    (this.o[name] || noop)(arg);
  };

  EmrVoiceStream.prototype._setState = function (s) {
    if (this.state !== s) { this.state = s; this._emit('onStateChange', s); }
  };

  EmrVoiceStream.prototype.connect = function () {
    var self = this;
    if (this.finished) return Promise.resolve();
    this._setState(this.written === 0 && this.attempt === 0 ? 'connecting' : 'reconnecting');
    var tokenP = this.o.getToken ? Promise.resolve(this.o.getToken()) : Promise.resolve(this.o.token);
    return tokenP.then(function (token) {
      var sep = self.o.url.indexOf('?') === -1 ? '?' : '&';
      var ws = new self.WS(self.o.url + sep + 'token=' + encodeURIComponent(token));
      ws.binaryType = 'arraybuffer';
      self.ws = ws;
      self.ready = false;
      ws.onmessage = function (ev) { if (typeof ev.data === 'string') self._onMessage(JSON.parse(ev.data)); };
      ws.onclose = function (ev) { self._onClose(ws, ev); };
      ws.onerror = noop; // onclose follows
    }, function (err) {
      self._emit('onError', { code: 'token', message: String(err && err.message || err) });
      self._scheduleReconnect();
    });
  };

  /** Append PCM16LE audio (ArrayBuffer / typed array). Safe to call while disconnected. */
  EmrVoiceStream.prototype.write = function (pcm) {
    if (this.finished || this.stopRequested || this.cancelRequested) return;
    var data = pcm instanceof Uint8Array ? pcm : new Uint8Array(pcm.buffer ? pcm.buffer.slice(pcm.byteOffset, pcm.byteOffset + pcm.byteLength) : pcm);
    if (data.byteLength === 0) return;
    this.frames.push({ offset: this.written, data: data });
    this.written += data.byteLength;
    var buffered = this.written - this.acked;
    if (buffered > this.maxBufferBytes) {
      var dropped = this.frames.shift();
      this.acked = dropped.offset + dropped.data.byteLength; // audio lost: tell the app
      this._emit('onError', { code: 'buffer_full', message: 'Server unreachable for too long; oldest audio dropped' });
    }
    this._flush();
  };

  /** End of recording: flush, then ask the server to finish. Resolves with the "done" message. */
  EmrVoiceStream.prototype.stop = function () {
    this.stopRequested = true;
    this._setState('finishing');
    if (this.ready) this._sendStop();
    return this.donePromise;
  };

  EmrVoiceStream.prototype.cancel = function () {
    this.cancelRequested = true;
    if (this.ready) this.ws.send(JSON.stringify({ type: 'cancel' }));
    else this._finish({ type: 'done', cancelled: true, local: true });
    return this.donePromise;
  };

  EmrVoiceStream.prototype._sendStop = function () {
    this._flush();
    this.ws.send(JSON.stringify({ type: 'stop' }));
  };

  EmrVoiceStream.prototype._flush = function () {
    if (!this.ready || !this.ws || this.ws.readyState !== 1) return;
    for (var i = 0; i < this.frames.length; i++) {
      var f = this.frames[i];
      var end = f.offset + f.data.byteLength;
      if (end <= this.sentUpTo) continue;
      var from = Math.max(0, this.sentUpTo - f.offset);
      this.ws.send(from === 0 ? f.data : f.data.subarray(from));
      this.sentUpTo = end;
    }
  };

  EmrVoiceStream.prototype._onMessage = function (m) {
    switch (m.type) {
      case 'ready':
        this.ready = true;
        this.attempt = 0;
        this.sentUpTo = m.resumeOffset;
        this._ack(m.resumeOffset);
        this._setState(this.stopRequested ? 'finishing' : 'recording');
        this._emit('onReady', m);
        if (this.cancelRequested) { this.ws.send(JSON.stringify({ type: 'cancel' })); return; }
        if (this.stopRequested) { this._sendStop(); return; }
        this._flush();
        break;
      case 'ack': this._ack(m.offset); this._emit('onAck', m.offset); break;
      case 'chunk': this._emit('onChunk', m); break;
      case 'silence': this._emit('onSilence', m); break;
      case 'finishing': this._emit('onFinishing', m); break;
      case 'done': this._finish(m); break;
      case 'error': this._emit('onError', m); break;
    }
  };

  EmrVoiceStream.prototype._ack = function (offset) {
    if (offset <= this.acked) return;
    this.acked = offset;
    while (this.frames.length && this.frames[0].offset + this.frames[0].data.byteLength <= offset) this.frames.shift();
  };

  EmrVoiceStream.prototype._finish = function (m) {
    if (this.finished) return;
    this.finished = true;
    this.frames = [];
    this._setState(m.cancelled ? 'cancelled' : (m.failed ? 'failed' : 'done'));
    this._emit('onDone', m);
    if (this.ws && this.ws.readyState <= 1) this.ws.close(1000);
    this._resolve(m);
  };

  EmrVoiceStream.prototype._onClose = function (ws, ev) {
    if (ws !== this.ws) return;
    this.ready = false;
    if (this.finished) return;
    if (NO_RECONNECT_CODES[ev.code]) {
      this._emit('onError', { code: 'closed', message: NO_RECONNECT_CODES[ev.code] });
      this._setState('failed');
      this.finished = true;
      this._reject(new Error(NO_RECONNECT_CODES[ev.code]));
      return;
    }
    this._scheduleReconnect();
  };

  EmrVoiceStream.prototype._scheduleReconnect = function () {
    var self = this;
    var delay = RECONNECT_DELAYS_MS[Math.min(this.attempt, RECONNECT_DELAYS_MS.length - 1)];
    this.attempt++;
    this._setState('reconnecting');
    setTimeout(function () { self.connect(); }, delay);
  };

  /**
   * Microphone recorder. options: everything EmrVoiceStream takes, plus
   *   workletUrl (default: emr-voice-worklet.js next to this script), frameMs (100), onLevel(rms),
   *   audio: MediaTrackConstraints (default mono with echo cancellation, noise suppression, auto gain)
   */
  function EmrVoiceRecorder(options) {
    this.o = options;
    this.stream = new EmrVoiceStream(options);
  }

  var scriptSrc = (global.document && global.document.currentScript && global.document.currentScript.src) || '';

  EmrVoiceRecorder.start = function (options) {
    var r = new EmrVoiceRecorder(options);
    return r._start().then(function () { return r; });
  };

  EmrVoiceRecorder.prototype._start = function () {
    var self = this, o = this.o;
    var constraints = o.audio || { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true };
    var workletUrl = o.workletUrl || (scriptSrc ? scriptSrc.replace(/[^/]*$/, 'emr-voice-worklet.js') : '/vendor/emr-voice/emr-voice-worklet.js');
    return navigator.mediaDevices.getUserMedia({ audio: constraints }).then(function (media) {
      self.media = media;
      self.ctx = self._context(media);
      return self.ctx.audioWorklet.addModule(workletUrl);
    }).then(function () {
      self.node = new AudioWorkletNode(self.ctx, 'emr-voice-pcm', { processorOptions: { frameMs: o.frameMs || 100 } });
      self.node.port.onmessage = function (e) {
        if (e.data.pcm) {
          self.stream.write(e.data.pcm);
          if (o.onLevel) o.onLevel(e.data.rms);
        } else if (e.data.flushed && self._onFlushed) {
          self._onFlushed();
        }
      };
      self.source.connect(self.node);
      return self.stream.connect();
    }).catch(function (err) {
      self._release();
      throw err;
    });
  };

  EmrVoiceRecorder.prototype._context = function (media) {
    var AC = global.AudioContext || global.webkitAudioContext;
    try {
      var ctx16 = new AC({ sampleRate: SAMPLE_RATE });
      this.source = ctx16.createMediaStreamSource(media); // Firefox throws if the mic runs at another rate
      return ctx16;
    } catch (e) {
      var ctx = new AC();
      this.source = ctx.createMediaStreamSource(media);    // the worklet resamples
      return ctx;
    }
  };

  /** Stop the microphone, send the remaining audio and finish. Resolves with the server's "done" message. */
  EmrVoiceRecorder.prototype.stop = function () {
    var self = this;
    return new Promise(function (resolve) {
      if (!self.node) return resolve();
      self._onFlushed = resolve;
      self.node.port.postMessage('flush');
      setTimeout(resolve, 500);
    }).then(function () {
      self._release();
      return self.stream.stop();
    });
  };

  EmrVoiceRecorder.prototype.cancel = function () {
    this._release();
    return this.stream.cancel();
  };

  Object.defineProperty(EmrVoiceRecorder.prototype, 'state', { get: function () { return this.stream.state; } });

  EmrVoiceRecorder.prototype._release = function () {
    if (this.media) this.media.getTracks().forEach(function (t) { t.stop(); });
    if (this.source) try { this.source.disconnect(); } catch (e) { /* already */ }
    if (this.ctx && this.ctx.state !== 'closed') this.ctx.close();
    this.media = this.source = this.node = null;
  };

  global.EmrVoiceRecorder = EmrVoiceRecorder;
  global.EmrVoiceStream = EmrVoiceStream;
  if (typeof module !== 'undefined' && module.exports) module.exports = { EmrVoiceRecorder: EmrVoiceRecorder, EmrVoiceStream: EmrVoiceStream };
})(typeof window !== 'undefined' ? window : globalThis);
