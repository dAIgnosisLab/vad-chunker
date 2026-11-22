<?php

namespace EmrVoice\Client;

use EmrVoice\Exceptions\EmrVoiceException;

class ApiError extends EmrVoiceException
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
        parent::__construct("EMR API returned HTTP {$status}: " . mb_strimwidth($body, 0, 300, '…'));
    }

    /** 4xx other than 408/429 will not succeed on retry. */
    public function isPermanent(): bool
    {
        return $this->status >= 400 && $this->status < 500 && !in_array($this->status, [408, 429], true);
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $v = json_decode($this->body, true);
        return is_array($v) ? $v : null;
    }
}
