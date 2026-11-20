<?php

namespace App\Domain\Llm;

final readonly class LlmResponse
{
    public function __construct(public string $content, public int $tokensIn, public int $tokensOut) {}
}
