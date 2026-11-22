<?php

namespace EmrVoice\Auth;

use EmrVoice\Exceptions\InvalidToken;

/** Short-lived HMAC-SHA256 tokens that bind a browser connection to one recording. */
final class TokenSigner
{
    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('emr-voice.auth.secret is empty (set EMR_VOICE_SECRET or APP_KEY)');
        }
    }

    /** @param array<string, mixed> $claims */
    public function sign(array $claims, int $ttlSeconds, ?int $now = null): string
    {
        $claims['exp'] = ($now ?? time()) + $ttlSeconds;
        $body = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, $this->secret, true));
    }

    /** @return array<string, mixed> */
    public function verify(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new InvalidToken('Malformed token');
        }
        [$body, $sig] = $parts;
        if (!hash_equals(self::b64(hash_hmac('sha256', $body, $this->secret, true)), $sig)) {
            throw new InvalidToken('Bad token signature');
        }
        $claims = json_decode(self::unb64($body), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['rid'])) {
            throw new InvalidToken('Malformed token claims');
        }
        if ($claims['exp'] < ($now ?? time())) {
            throw new InvalidToken('Token expired');
        }
        return $claims;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
