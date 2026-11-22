<?php

namespace EmrVoice\Tests\Support;

use EmrVoice\Audio\Pcm;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;

/**
 * In-process stand-in for the EMR API + S3, with the same chunk-index rules as midas-api
 * (explicit chunkIndex is idempotent, skipping ahead is a 409, one final index per session).
 */
final class FakeEmrApi
{
    public string $baseUrl;
    /** @var array<string, array{hospitalId: string, doctorId: string, patientId: string, count: int, final: ?int, keys: array<int, string>}> */
    public array $sessions = [];
    /** @var array<string, array<int, string>> sessionId → chunkIndex → PCM (from the uploaded WAV) */
    public array $objects = [];
    public int $putCount = 0;
    /** @var list<array{0: string, 1: string}> */
    public array $log = [];
    /** @var array<string, int> "put:1" => n, "url:2" => n — fail that call n times with HTTP 500 */
    public array $failures = [];
    /** @var array<string, int> "url:2" => n — perform the call but answer 500 n times (lost response) */
    public array $lostResponses = [];
    public bool $requireKey = true;
    /** @var (callable(): array)|null */
    public $onDebug = null;

    private SocketServer $socket;

    public function __construct(LoopInterface $loop, public readonly string $apiKey = 'test-key')
    {
        // explicit buffer: the default derives from post_max_size/memory_limit and can drop a 2 MB chunk body
        $http = new HttpServer($loop, new StreamingRequestMiddleware(), new RequestBodyBufferMiddleware(16 * 1024 * 1024),
            fn (ServerRequestInterface $r) => $this->handle($r));
        $this->socket = new SocketServer('127.0.0.1:0', [], $loop);
        $http->listen($this->socket);
        $this->baseUrl = str_replace('tcp://', 'http://', $this->socket->getAddress());
    }

    public function close(): void
    {
        $this->socket->close();
    }

    private function handle(ServerRequestInterface $r): Response
    {
        $path = $r->getUri()->getPath();
        $this->log[] = [$r->getMethod(), $path];

        if (preg_match('#^/s3/([^/]+)/(\d+)$#', $path, $m) && $r->getMethod() === 'PUT') {
            if ($this->fail("put:{$m[2]}")) {
                return new Response(500, [], 'injected');
            }
            if ($r->getHeaderLine('Content-Type') !== 'audio/wav') {
                return new Response(403, [], 'SignatureDoesNotMatch');
            }
            $this->putCount++;
            $this->objects[$m[1]][(int) $m[2]] = Pcm::fromWav((string) $r->getBody());
            return new Response(200);
        }
        if ($path === '/debug/state' && $this->onDebug) {
            return Response::json(($this->onDebug)());
        }
        if ($path === '/health') {
            return Response::json(['ok' => true]);
        }
        if ($this->requireKey && $r->getHeaderLine('X-API-Key') !== $this->apiKey) {
            return Response::json(['error' => 'Invalid API key'])->withStatus(401);
        }
        $body = json_decode((string) $r->getBody(), true) ?? [];

        if ($path === '/api/v1/sessions' && $r->getMethod() === 'POST') {
            $id = bin2hex(random_bytes(8));
            $this->sessions[$id] = ['hospitalId' => $body['hospitalId'], 'doctorId' => $body['doctorId'], 'patientId' => $body['patientId'], 'count' => 0, 'final' => null, 'keys' => []];
            return Response::json(['sessionId' => $id])->withStatus(201);
        }
        if (preg_match('#^/api/v1/sessions/([^/]+)/chunks/url$#', $path, $m)) {
            $s = &$this->sessions[$m[1]];
            if (!$s) {
                return Response::json(['error' => 'Session not found'])->withStatus(404);
            }
            $want = $body['chunkIndex'] ?? $s['count'];
            if ($this->fail("url:$want")) {
                return new Response(500, [], 'injected');
            }
            if ($want > $s['count']) {
                return Response::json(['error' => 'skips ahead', 'nextChunkIndex' => $s['count']])->withStatus(409);
            }
            if ($want === $s['count']) {
                $s['count']++;
                $s['keys'][$want] = "/s3/{$m[1]}/$want";
            }
            if (!empty($body['isFinal'])) {
                if ($s['final'] !== null && $s['final'] !== $want) {
                    return Response::json(['error' => 'Final chunk already declared'])->withStatus(409);
                }
                $s['final'] = $want;
            }
            if (($this->lostResponses["url:$want"] ?? 0) > 0) {
                $this->lostResponses["url:$want"]--;
                return new Response(500, [], 'lost response');
            }
            return Response::json(['chunkIndex' => $want, 'uploadUrl' => $this->baseUrl . $s['keys'][$want], 'isFinal' => !empty($body['isFinal'])]);
        }
        if (preg_match('#^/api/v1/sessions/([^/]+)/result$#', $path, $m)) {
            return Response::json(['sessionId' => $m[1], 'status' => 'completed', 'emr' => ['note' => 'fake']]);
        }
        return new Response(404);
    }

    private function fail(string $key): bool
    {
        if (($this->failures[$key] ?? 0) > 0) {
            $this->failures[$key]--;
            return true;
        }
        return false;
    }
}
