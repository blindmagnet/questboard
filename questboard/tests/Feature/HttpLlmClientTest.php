<?php

use App\Domain\Llm\CircuitBreaker;
use App\Domain\Llm\LlmException;
use App\Domain\Llm\Providers\HttpLlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function httpClient(?string $key = 'sk-test', int $threshold = 2): HttpLlmClient
{
    return new HttpLlmClient('https://llm.example.com/v1', $key, 'test-model', 5, 1, new CircuitBreaker(Cache::store(), 'llm-test', $threshold, 60));
}

it('calls an OpenAI-compatible endpoint and reads usage', function () {
    Http::fake(['llm.example.com/*' => Http::response([
        'choices' => [['message' => ['content' => '{"quests":[]}']]],
        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
    ])]);

    $res = httpClient()->complete([['role' => 'user', 'content' => 'hi']]);

    expect($res->content)->toBe('{"quests":[]}')->and($res->tokensIn)->toBe(120)->and($res->tokensOut)->toBe(30);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.example.com/v1/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer sk-test')
        && $r['model'] === 'test-model'
        && $r['response_format'] === ['type' => 'json_object']);
});

it('opens the circuit after repeated failures and stops calling the provider', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    $client = httpClient(threshold: 2);

    foreach (range(1, 2) as $_) {
        expect(fn () => $client->complete([['role' => 'user', 'content' => 'x']]))->toThrow(LlmException::class);
    }
    $sent = count(Http::recorded());

    expect(fn () => $client->complete([['role' => 'user', 'content' => 'x']]))->toThrow(LlmException::class, 'circuit is open')
        ->and(count(Http::recorded()))->toBe($sent);
});

it('refuses to run without an api key', function () {
    expect(fn () => httpClient(key: null)->complete([]))->toThrow(LlmException::class, 'LLM_API_KEY');
});
