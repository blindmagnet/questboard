<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToGame;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_id
 * @property ?int $requested_by
 * @property ?int $prompt_version_id
 * @property array<string, mixed> $input
 * @property ?array<string, mixed> $output
 * @property int $tokens_in
 * @property int $tokens_out
 * @property string $status
 * @property int $attempts
 */
class LlmGeneration extends Model
{
    use BelongsToGame;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    protected $attributes = ['status' => self::STATUS_QUEUED, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_estimate' => 0, 'attempts' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'moderation_result' => 'array',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost_estimate' => 'float',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<PromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }
}
