// Tests EmrVoiceStream (resources/js/emr-voice-recorder.js) against the real PHP server + fake EMR API.
//   node tests/js/stream.test.mjs        (needs Docker image emr-voice-dev and tests/fixtures/build/talk.wav)
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const { EmrVoiceStream } = createRequire(import.meta.url)(path.join(root, 'resources/js/emr-voice-recorder.js'));

function wavData(file) {
  const b = fs.readFileSync(file);
  for (let p = 12; p + 8 <= b.length;) {
    const id = b.toString('ascii', p, p + 4), size = b.readUInt32LE(p + 4);
    if (id === 'data') return b.subarray(p + 8, p + 8 + size);
    p += 8 + size + (size % 2);
  }
  throw new Error('no data');
}

const port = 8099;
const server = spawn('docker', ['run', '--rm', '--network', 'host', '-u', `${process.getuid()}:${process.getgid()}`, '-v', `${root}:/pkg`, 'emr-voice-dev', 'php', 'tests/js/serve.php', String(port)]);
let serverLog = '';
server.stderr.on('data', (d) => { serverLog += d; });
const info = await new Promise((resolve, reject) => {
  let out = '';
  server.stdout.on('data', (d) => { out += d; const nl = out.indexOf('\n'); if (nl >= 0) resolve(JSON.parse(out.slice(0, nl))); });
  server.on('exit', (c) => reject(new Error('server exited ' + c + '\n' + serverLog)));
  setTimeout(() => reject(new Error('server did not start\n' + serverLog)), 30000);
});
const state = async () => (await fetch(info.apiUrl + '/debug/state')).json();
const pcm = wavData(path.join(root, 'tests/fixtures/build/talk.wav'));
const FRAME = 3200;

/** Streams `pcm` in 100 ms frames, `perTick` frames per ms; `hooks.onFrame(i, stream)` can interfere. */
function play(stream, perTick, hooks = {}) {
  return new Promise((resolve) => {
    let pos = 0, i = 0;
    const t = setInterval(() => {
      for (let k = 0; k < perTick; k++) {
        if (pos >= pcm.length || stream.finished) { clearInterval(t); return resolve(); }
        if (hooks.onFrame) hooks.onFrame(i, stream);
        stream.write(pcm.subarray(pos, pos + FRAME));
        pos += FRAME; i++;
      }
    }, 1);
  });
}

function assertSlicesInOrder(chunks) {
  let at = 0;
  for (const [i, c] of Object.entries(chunks)) {
    const buf = Buffer.from(c.b64, 'base64');
    const found = pcm.indexOf(buf, at);
    assert.ok(found >= 0 && found % 2 === 0, `chunk ${i} is an exact slice of the stream, after the previous one`);
    at = found + buf.length;
  }
}

let failed = 0;
async function test(name, fn) {
  try { await fn(); console.log('ok  -', name); } catch (e) { failed++; console.log('FAIL-', name, '\n', e); }
}

await test('streams, survives two connection drops, and finishes with contiguous chunks', async () => {
  const states = [], chunks = [];
  let tokenCalls = 0, drops = 0;
  const s = new EmrVoiceStream({
    url: info.wsUrl, WebSocket,
    getToken: async () => { tokenCalls++; return info.tokens[0]; },
    onStateChange: (x) => states.push(x),
    onChunk: (c) => chunks.push(c),
  });
  await s.connect();
  await play(s, 1, { onFrame: (i, st) => { if ((i === 500 || i === 1100) && st.ws) { drops++; st.ws.close(); } } });
  const done = await s.stop();
  assert.equal(drops, 2);
  assert.ok(tokenCalls >= 3, 'a fresh token is fetched for every (re)connect');
  assert.ok(states.includes('reconnecting'), 'went through reconnecting');
  assert.equal(states.at(-1), 'done');
  const st = await state();
  const sid = Object.keys(st.sessions).find((k) => st.sessions[k].patientId === 'pt-js');
  const got = st.chunks[sid];
  assert.deepEqual(Object.keys(got).map(Number), [...Array(Object.keys(got).length).keys()], 'indices 0..n-1');
  assert.equal(st.sessions[sid].final, Object.keys(got).length - 1, 'last chunk is final');
  assert.equal(done.chunks, Object.keys(got).length);
  assertSlicesInOrder(got);
  const seconds = Object.values(got).reduce((a, c) => a + c.samples, 0) / 16000;
  assert.ok(seconds > 160 && seconds <= 169.2, `about the whole recording was delivered (${seconds.toFixed(1)} s of 169.2 s)`);
  assert.equal(s.frames.length, 0, 'nothing left buffered');
});

await test('cancel mid-recording sends no final chunk', async () => {
  const s = new EmrVoiceStream({ url: info.wsUrl, WebSocket, token: info.tokens[1] });
  await s.connect();
  let cancelled;
  await play(s, 1, { onFrame: (i, st) => { if (i === 700) cancelled = st.cancel(); } });
  const done = await cancelled;
  assert.equal(done.cancelled, true);
  const st = await state();
  const sid = Object.keys(st.sessions).at(-1);
  assert.equal(st.sessions[sid].final, null);
});

await test('stop while disconnected reconnects, flushes and finishes', async () => {
  const s = new EmrVoiceStream({ url: info.wsUrl, WebSocket, token: info.tokens[2] });
  await s.connect();
  await play(s, 20);
  s.ws.close();
  const done = await s.stop();
  assert.ok(done.chunks >= 3);
  assert.equal(s.state, 'done');
});

server.kill();
spawn('docker', ['ps', '-q', '--filter', 'ancestor=emr-voice-dev']).stdout.on('data', (ids) => spawn('docker', ['kill', ...String(ids).trim().split('\n')]));
if (failed) { console.log('\nserver log tail:\n' + serverLog.split('\n').slice(-15).join('\n')); process.exit(1); }
console.log('all JS stream tests passed');
