<?php

namespace VadChunker\Tests\Feature;

use Orchestra\Testbench\TestCase;
use VadChunker\Audio\Pcm;
use VadChunker\Chunk;
use VadChunker\Facades\VadChunker as VadChunkerFacade;
use VadChunker\Tests\Support\Model;
use VadChunker\VadChunker;
use VadChunker\VadChunkerServiceProvider;

final class VadChunkerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [VadChunkerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('vad-chunker.runtime_path', Model::libPath());
    }

    public function test_snapshot_and_restore_between_every_piece_matches_one_continuous_stream(): void
    {
        foreach (['talk.wav', 'gaps.wav'] as $fixture) {
            $pcm = $this->fixture($fixture);
            $pieces = $this->randomPieces(strlen($pcm), crc32($fixture));

            $continuous = new VadChunker(Model::get());
            $expected = $this->stream($continuous, $pcm, $pieces);

            $got = [];
            $snapshot = (new VadChunker(Model::get()))->snapshot();
            $pos = 0;
            foreach ($pieces as $n) {                   // a new "request" per piece
                $c = VadChunker::restore($snapshot, Model::get());
                array_push($got, ...$c->feed(substr($pcm, $pos, $n)));
                $snapshot = $c->snapshot();
                $pos += $n;
            }
            array_push($got, ...VadChunker::restore($snapshot, Model::get())->finish());

            $this->assertNotEmpty($expected);
            $this->assertSame($this->describe($expected), $this->describe($got), "$fixture: restored stream = continuous stream");
        }
    }

    public function test_odd_byte_boundaries_do_not_shift_samples(): void
    {
        $pcm = $this->fixture('talk.wav');
        $even = $this->stream(new VadChunker(Model::get()), $pcm, array_fill(0, (int) ceil(strlen($pcm) / 3200), 3200));
        $odd = $this->stream(new VadChunker(Model::get()), $pcm, array_fill(0, (int) ceil(strlen($pcm) / 3201), 3201));
        $this->assertSame(array_map(fn (Chunk $c) => sha1($c->pcm), $even), array_map(fn (Chunk $c) => sha1($c->pcm), $odd));
    }

    public function test_chunk_wav_and_chunk_objects(): void
    {
        $wav = file_get_contents(Model::fixture('talk.wav'));
        $chunks = VadChunker::chunkWav($wav, Model::get());

        $this->assertCount(3, $chunks);
        $this->assertSame([0, 1, 2], array_map(fn (Chunk $c) => $c->index, $chunks));
        $this->assertSame(['pause', 'pause', 'end'], array_map(fn (Chunk $c) => $c->reason, $chunks));
        foreach ($chunks as $c) {
            $this->assertSame($c->pcm, Pcm::fromWav($c->wav()), 'wav() is a valid 16 kHz mono WAV of the chunk');
            $this->assertGreaterThan(40, $c->seconds());
            $this->assertNotFalse(strpos(Pcm::fromWav($wav), $c->pcm), 'audio is an exact slice of the input');
        }
        $this->assertSame(['index', 'start', 'end', 'reason', 'speechRatio', 'longestSpeechS', 'speechStart', 'speechEnd', 'trimmedS', 'seconds'],
            array_keys($chunks[0]->toArray()));
    }

    public function test_silence_is_reported_and_kept_out_of_the_chunks(): void
    {
        $c = new VadChunker(Model::get());
        $chunks = $this->stream($c, $this->fixture('gaps.wav'), array_fill(0, 500, 32000));
        $silences = $c->silences();
        $this->assertNotEmpty($silences);
        foreach ($silences as $s) {
            $this->assertGreaterThanOrEqual(5, $s['durationS'], 'only spans >= silence_report_s are reported');
        }
        $audio = array_sum(array_map(fn (Chunk $x) => $x->seconds(), $chunks));
        $this->assertLessThan(221.6 - 60, $audio, 'the 25 s and 40 s gaps (and the noise gap) are dropped or trimmed');
        $this->assertGreaterThan(0, array_sum(array_map(fn (Chunk $x) => $x->trimmedS, $chunks)), 'long silence at chunk edges is trimmed');
    }

    public function test_laravel_facade_with_config_overrides(): void
    {
        $chunks = VadChunkerFacade::chunkWav(file_get_contents(Model::fixture('talk.wav')), ['min_s' => 20, 'target_s' => 25, 'max_s' => 35]);
        $this->assertGreaterThan(4, count($chunks));
        foreach (array_slice($chunks, 0, -1) as $c) {
            $this->assertLessThanOrEqual(35.001, $c->end - $c->start);
        }
        $stream = VadChunkerFacade::make();
        $this->assertSame([], $stream->feed(str_repeat("\0", 32000)));
        $this->assertInstanceOf(VadChunker::class, VadChunkerFacade::restore($stream->snapshot()));
    }

    /** @param list<int> $pieces @return list<Chunk> */
    private function stream(VadChunker $c, string $pcm, array $pieces): array
    {
        $out = [];
        $pos = 0;
        foreach ($pieces as $n) {
            array_push($out, ...$c->feed(substr($pcm, $pos, $n)));
            $pos += $n;
        }
        array_push($out, ...$c->finish());
        return $out;
    }

    /** @return list<int> */
    private function randomPieces(int $total, int $seed): array
    {
        mt_srand($seed);
        $out = [];
        for ($sum = 0; $sum < $total; $sum += $n) {
            $n = min($total - $sum, mt_rand(1, 200000));
            $out[] = $n;
        }
        return $out;
    }

    private function describe(array $chunks): array
    {
        return array_map(fn (Chunk $c) => $c->toArray() + ['sha1' => sha1($c->pcm)], $chunks);
    }

    private function fixture(string $name): string
    {
        $path = Model::fixture($name) ?? $this->markTestSkipped("fixture $name not built (run tests/fixtures/build.sh)");
        return Pcm::fromWav(file_get_contents($path));
    }
}
