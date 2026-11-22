// Runs the real `php artisan emr-voice:serve` (via Testbench) against the standalone fake API and records
// through it with EmrVoiceStream; then checks SIGTERM shuts it down cleanly.
//   node tests/js/serve-command.test.mjs
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const { EmrVoiceStream } = createRequire(import.meta.url)(path.join(root, 'resources/js/emr-voice-recorder.js'));
const docker = (name, env, ...cmd) => spawn('docker', ['run', '--rm', '--name', name, '--network', 'host', '-u', `${process.getuid()}:${process.getgid()}`,
  ...Object.entries(env).flatMap(([k, v]) => ['-e', `${k}=${v}`]), '-v', `${root}:/pkg`, 'emr-voice-dev', ...cmd]);
const firstLine = (proc, match) => new Promise((resolve, reject) => {
  let out = '';
  const on = (d) => { out += d; const l = out.split('\n').find((x) => match.test(x)); if (l) resolve(l.trim()); };
  proc.stdout.on('data', on); proc.stderr.on('data', on);
  proc.on('exit', (c) => reject(new Error(`exited ${c}: ${out}`)));
  setTimeout(() => reject(new Error('timeout: ' + out)), 60000);
});

const b64url = (b) => Buffer.from(b).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
function token(secret, claims) {           // same format as EmrVoice\Auth\TokenSigner
  const body = b64url(JSON.stringify({ ...claims, exp: Math.floor(Date.now() / 1000) + 600 }));
  return body + '.' + b64url(crypto.createHmac('sha256', secret).update(body).digest());
}

const api = docker('emr-voice-fakeapi', {}, 'php', 'tests/js/fake-api.php', 'serve-key');
const apiUrl = await firstLine(api, /^http:\/\//);
const secret = 'serve-cmd-secret';
const serve = docker('emr-voice-serve', { EMR_VOICE_API_URL: apiUrl, EMR_VOICE_API_KEY: 'serve-key', EMR_VOICE_SECRET: secret, EMR_VOICE_WS_PORT: '8097',
  EMR_VOICE_RUNTIME_PATH: '/pkg/runtime/libonnxruntime.so.1', LOG_CHANNEL: 'stderr',
  QUEUE_CONNECTION: 'database' /* no jobs table: the RecordingFinished listener throws, the recording must still finish */ }, 'vendor/bin/testbench', 'emr-voice:serve');
let serveLog = '';
serve.stdout.on('data', (d) => { serveLog += d; });
serve.stderr.on('data', (d) => { serveLog += d; });
await firstLine(serve, /listening on ws:/);

try {
  const pcm = fs.readFileSync(path.join(root, 'tests/fixtures/build/talk.wav')).subarray(44);
  const s = new EmrVoiceStream({ url: 'ws://127.0.0.1:8097/vad', WebSocket, token: token(secret, { rid: 'serve-' + Date.now(), pid: 'pt-s', did: 'dr-s', hid: 'h-s' }),
    onStateChange: (x) => process.env.DEBUG && console.log('state', x), onError: (e) => process.env.DEBUG && console.log('error', e), onChunk: (c) => process.env.DEBUG && console.log('chunk', c.index) });
  await s.connect();
  for (let p = 0; p < pcm.length; p += 3200) {
    s.write(pcm.subarray(p, p + 3200));
    if (p % 64000 === 0) await new Promise((r) => setTimeout(r, 1));
  }
  const done = await s.stop();
  const st = await (await fetch(apiUrl + '/debug/state')).json();
  const [sid] = Object.keys(st.sessions);
  assert.equal(st.sessions[sid].hospitalId, 'h-s');
  assert.equal(Object.keys(st.chunks[sid]).length, done.chunks);
  assert.equal(st.sessions[sid].final, done.chunks - 1);
  console.log(`ok  - emr-voice:serve recorded ${done.chunks} chunks through the fake API`);
  assert.match(serveLog, /chunk uploaded/, 'logs go to the configured channel');
  assert.match(serveLog, /event listener failed/, 'a failing host listener is logged, not fatal');
  console.log('ok  - a throwing event listener does not break the recording');

  const bad = await new Promise((resolve) => {
    const ws = new WebSocket('ws://127.0.0.1:8097/vad?token=nope');
    ws.onclose = (e) => resolve(e.code);
  });
  assert.equal(bad, 1006, 'bad token is refused before the upgrade');
  console.log('ok  - bad token refused');

  const exited = new Promise((resolve) => serve.on('exit', (code) => resolve(code)));
  spawn('docker', ['kill', '--signal', 'TERM', 'emr-voice-serve']);
  const code = await Promise.race([exited, new Promise((r) => setTimeout(() => r('timeout'), 15000))]);
  assert.equal(code, 0, 'SIGTERM → clean exit');
  console.log('ok  - SIGTERM stops the server cleanly');
  console.log('all serve-command tests passed');
} catch (e) {
  console.log('FAIL', e, '\n--- serve log ---\n' + serveLog.split('\n').slice(-20).join('\n'));
  process.exitCode = 1;
} finally {
  spawn('docker', ['kill', 'emr-voice-serve', 'emr-voice-fakeapi']);
}
