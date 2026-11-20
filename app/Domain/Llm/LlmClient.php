<?php

namespace App\Domain\Llm;

interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws LlmException
     */
    public function complete(array $messages): LlmResponse;
}
