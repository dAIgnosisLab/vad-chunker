<?php

namespace EmrVoice\Client;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

/** Non-blocking EMR API client used inside the WebSocket event loop. */
final class AsyncEmrApiClient
{
    private Browser $http;

    public function __construct(LoopInterface $loop, private readonly string $baseUrl, private readonly ?string $apiKey, float $timeout)
    {
        $this->http = (new Browser(null, $loop))->withTimeout($timeout)->withRejectErrorResponse(true);
    }

    /** @return PromiseInterface<string> resolves to our sessionId */
    public function createSession(string $hospitalId, string $doctorId, string $patientId, ?array $format = null): PromiseInterface
    {
        $body = array_filter(compact('hospitalId', 'doctorId', 'patientId', 'format'), fn ($v) => $v !== null);
        return $this->json('POST', '/api/v1/sessions', $body)->then(fn (array $r) => (string) $r['sessionId']);
    }

    /** @return PromiseInterface<array{uploadUrl: string, chunkIndex: int}> */
    public function chunkUrl(string $sessionId, int $chunkIndex, bool $isFinal): PromiseInterface
    {
        return $this->json('POST', "/api/v1/sessions/{$sessionId}/chunks/url", ['chunkIndex' => $chunkIndex, 'isFinal' => $isFinal]);
    }

    /** @return PromiseInterface<ResponseInterface> */
    public function upload(string $uploadUrl, string $wav): PromiseInterface
    {
        return $this->http->put($uploadUrl, ['Content-Type' => 'audio/wav', 'Content-Length' => (string) strlen($wav)], $wav);
    }

    /** @return PromiseInterface<array<string, mixed>> */
    private function json(string $method, string $path, array $body): PromiseInterface
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->apiKey) {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $headers, json_encode($body, JSON_THROW_ON_ERROR))
            ->then(
                fn (ResponseInterface $r) => json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR),
                function (\Throwable $e) {
                    if ($e instanceof ResponseException) {
                        $r = $e->getResponse();
                        throw new ApiError($r->getStatusCode(), (string) $r->getBody());
                    }
                    throw $e;
                }
            );
    }
}
