<?php

use Illuminate\Support\Facades\Route;

/*
 * Contract guard: the committed OpenAPI document (docs/openapi.json, generated with
 * `php artisan scramble:export --path=docs/openapi.json`) must describe every API route.
 */
it('documents every api route and method in docs/openapi.json', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true);

    expect($spec['openapi'])->toStartWith('3.')
        ->and($spec['info']['title'])->toBe('QuestBoard API');

    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => [strtolower($m), '/'.substr($r->uri(), strlen('api/'))]))
        ->reject(fn ($pair) => isset($spec['paths'][$pair[1]][$pair[0]]))
        ->map(fn ($pair) => strtoupper($pair[0]).' '.$pair[1])
        ->values()->all();

    expect($missing)->toBe([]);
});

it('documents request schemas for core write endpoints', function () {
    $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true);
    $schemas = $spec['components']['schemas'];

    expect($schemas['CompleteQuestRequest']['required'])->toContain('score')
        ->and($schemas['GenerateQuestsRequest']['required'])->toEqualCanonicalizing(['theme', 'difficulty', 'count']);
});
