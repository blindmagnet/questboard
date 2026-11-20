<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

class AuditLogger
{
    /** @param array<string, mixed> $diff */
    public function log(string $action, ?Model $subject = null, array $diff = [], ?int $gameId = null): void
    {
        $request = request();

        AuditLog::query()->create([
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'game_id' => $gameId ?? ($subject && array_key_exists('game_id', $subject->getAttributes()) ? $subject->getAttribute('game_id') : null),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'diff' => $diff ?: null,
            'ip' => $request->ip(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * Before/after diff of a model's dirty attributes (secrets are masked).
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(Model $model): array
    {
        $diff = [];
        foreach ($model->getDirty() as $key => $value) {
            if (in_array($key, ['password', 'secret', 'signing_secret', 'api_key_hash', 'remember_token'], true)) {
                $diff[$key] = ['from' => '***', 'to' => '***'];

                continue;
            }
            $diff[$key] = ['from' => $model->getOriginal($key), 'to' => $value];
        }

        return $diff;
    }
}
