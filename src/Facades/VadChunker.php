<?php

namespace VadChunker\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \VadChunker\VadChunker make(array $vad = [])
 * @method static \VadChunker\VadChunker restore(string $snapshot, array $vad = [])
 * @method static list<\VadChunker\Chunk> chunkWav(string $wav, array $vad = [])
 *
 * @see \VadChunker\ChunkerManager
 */
class VadChunker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VadChunker\ChunkerManager::class;
    }
}
