<?php

namespace EmrVoice\Client;

use Illuminate\Http\Client\Factory;

/** Blocking EMR API client for use in the Laravel app (controllers, jobs). */
final class EmrApiClient
{
    public function __construct(private readonly Factory $http, private readonly string $baseUrl, private readonly ?string $apiKey, private readonly float $timeout)
    {
    }

    /** @return array{status: string, emr: mixed, updatedAt?: string} */
    public function result(string $sessionId): array
    {
        $r = $this->http->withHeaders(array_filter(['X-API-Key' => $this->apiKey]))
            ->acceptJson()
            ->timeout($this->timeout)
            ->get(rtrim($this->baseUrl, '/') . "/api/v1/sessions/{$sessionId}/result");
        if ($r->failed()) {
            throw new ApiError($r->status(), $r->body());
        }
        return $r->json();
    }

    public function ping(): bool
    {
        $r = $this->http->timeout($this->timeout)->get(rtrim($this->baseUrl, '/') . '/health');
        return $r->successful();
    }
}
