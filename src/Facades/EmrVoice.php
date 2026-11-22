<?php

namespace EmrVoice\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string token(string $recordingId, string $patientId, string $doctorId, ?string $hospitalId = null, ?array $format = null, ?int $ttlSeconds = null)
 * @method static array|null recording(string $recordingId)
 * @method static array result(string $recordingId)
 *
 * @see \EmrVoice\EmrVoiceManager
 */
class EmrVoice extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \EmrVoice\EmrVoiceManager::class;
    }
}
