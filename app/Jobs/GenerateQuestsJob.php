<?php

namespace App\Jobs;

use App\Domain\Llm\QuestGenerator;
use App\Models\LlmGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateQuestsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $generationId)
    {
        $this->onQueue('llm');
    }

    public function handle(QuestGenerator $generator): void
    {
        $generation = LlmGeneration::withoutGlobalScopes()->find($this->generationId);
        if ($generation) {
            $generator->run($generation);
        }
    }

    public function failed(Throwable $e): void
    {
        LlmGeneration::withoutGlobalScopes()->whereKey($this->generationId)
            ->whereIn('status', [LlmGeneration::STATUS_QUEUED, LlmGeneration::STATUS_RUNNING])
            ->update(['status' => LlmGeneration::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
