<?php

namespace VadChunker\Tests\Parity;

use VadChunker\Audio\Pcm;
use VadChunker\Tests\Support\Model;
use VadChunker\Vad\LiveChunker;
use VadChunker\Vad\StreamingVad;
use VadChunker\Vad\VadConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP LiveChunker must cut exactly where the production vad.js does (tests/reference/*.json,
 * produced by tests/reference/generate.mjs from the same fixtures, fed in the same piece sizes).
 */
final class ParityTest extends TestCase
{
    public static function references(): array
    {
        $dir = dirname(__DIR__) . '/reference';
        $files = array_merge(glob("$dir/*.json"), glob("$dir/private/*.json"));
        $out = [];
        foreach ($files as $f) {
            if (basename($f) === 'package.json') {
                continue;
            }
            $out[basename($f, '.json')] = [$f];
        }
        return $out;
    }

    #[DataProvider('references')]
    public function test_cuts_match_production_vad_js(string $refFile): void
    {
        $ref = json_decode(file_get_contents($refFile), true);
        $wav = Model::fixture($ref['fixture']) ?? $this->markTestSkipped("fixture {$ref['fixture']} not built (run tests/fixtures/build.sh)");
        $pcm = Pcm::fromWav(file_get_contents($wav));
        $this->assertSame($ref['samples'], Pcm::samples($pcm));

        $cfg = $ref['config'] === null ? new VadConfig() : new VadConfig(...$ref['config']);
        $chunker = new LiveChunker(Model::get(), $cfg);

        $chunks = [];
        $silences = [];
        $collect = function (array $cuts, $at) use (&$chunks, &$silences, $chunker) {
            foreach ($cuts as $c) {
                $chunks[] = $c['chunk'] + ['samples' => Pcm::samples($c['audio']), 'sha1' => sha1($c['audio']), 'emittedAfterPiece' => $at];
            }
            array_push($silences, ...$chunker->takeSilences());
        };
        $pos = 0;
        foreach ($ref['pieces'] as $i => $n) {
            $collect($chunker->feed(substr($pcm, $pos * 2, $n * 2)), $i);
            $pos += $n;
        }
        $collect($chunker->finish(), 'finish');

        $this->assertSameSize($ref['chunks'], $chunks, 'chunk count');
        foreach ($ref['chunks'] as $i => $want) {
            $got = $chunks[$i];
            foreach (['index', 'reason', 'samples', 'emittedAfterPiece', 'sha1'] as $k) {
                $this->assertSame($want[$k], $got[$k], "chunk #$i $k");
            }
            foreach (['start', 'end', 'speechRatio', 'longestSpeechS', 'speechStart', 'speechEnd', 'trimmedS'] as $k) {
                $this->assertEqualsWithDelta($want[$k], $got[$k], 1e-9, "chunk #$i $k");
            }
        }
        $this->assertEquals($ref['silences'], $silences, 'reported silences');
        $this->assertEqualsWithDelta($ref['droppedS'], round($chunker->droppedS, 3), 1e-9, 'dropped silence');

        $probs = (new StreamingVad(Model::get()))->feed(substr($pcm, 0, min(Pcm::samples($pcm), 60 * 16000) * 2));
        $this->assertSameSize($ref['probs60s'], $probs);
        $maxDiff = 0.0;
        foreach ($probs as $k => $p) {
            $maxDiff = max($maxDiff, abs($p - $ref['probs60s'][$k]));
        }
        $this->assertLessThan(1e-5, $maxDiff, 'speech probabilities');
    }
}
