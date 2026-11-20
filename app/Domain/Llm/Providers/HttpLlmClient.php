<?php

namespace App\Domain\Llm\Providers;

use App\Domain\Llm\CircuitBreaker;
use App\Domain\Llm\LlmClient;
use App\Domain\Llm\LlmException;
use App\Domain\Llm\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** OpenAI-compatible Chat Completions client (works with OpenAI, OpenRouter, Ollama, vLLM...). */
class HttpLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function complete(array $messages): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new LlmException('LLM_API_KEY is not configured.');
        }
        if ($this->breaker->isOpen()) {
            throw new LlmException('LLM provider circuit is open; try again later.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->retry($this->retries, fn (int $attempt) => 500 * 2 ** ($attempt - 1), function ($e) {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
                })
                ->post('/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'response_format' => ['type' => 'json_object'],
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            $this->breaker->recordFailure();
            throw new LlmException('LLM request failed: '.$e->getMessage(), previous: $e);
        }

        $this->breaker->recordSuccess();

        return new LlmResponse(
            (string) data_get($response, 'choices.0.message.content', ''),
            (int) data_get($response, 'usage.prompt_tokens', 0),
            (int) data_get($response, 'usage.completion_tokens', 0),
        );
    }
}
