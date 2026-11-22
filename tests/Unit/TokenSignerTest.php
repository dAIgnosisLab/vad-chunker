<?php

namespace EmrVoice\Tests\Unit;

use EmrVoice\Auth\TokenSigner;
use EmrVoice\Exceptions\InvalidToken;
use PHPUnit\Framework\TestCase;

final class TokenSignerTest extends TestCase
{
    public function test_round_trip(): void
    {
        $s = new TokenSigner('secret');
        $claims = $s->verify($s->sign(['rid' => 'r1', 'pid' => 'p', 'did' => 'd'], 60));
        $this->assertSame(['rid' => 'r1', 'pid' => 'p', 'did' => 'd'], array_diff_key($claims, ['exp' => 0]));
    }

    public function test_rejects_tampering_expiry_and_other_secrets(): void
    {
        $s = new TokenSigner('secret');
        $token = $s->sign(['rid' => 'r1', 'pid' => 'p', 'did' => 'd'], 60, now: 1000);

        [$body, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['rid' => 'r1', 'pid' => 'OTHER', 'did' => 'd', 'exp' => 1060])), '+/', '-_'), '=') . '.' . $sig;
        foreach ([[$forged, 1000], [$token, 1061], ['garbage', 1000]] as [$t, $now]) {
            try {
                $s->verify($t, $now);
                $this->fail('accepted ' . $t);
            } catch (InvalidToken) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidToken::class);
        (new TokenSigner('other'))->verify($token, 1000);
    }
}
