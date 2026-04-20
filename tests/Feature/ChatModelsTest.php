<?php

use Illuminate\Support\Facades\Http;

test('returns available_models for the configured non-ollama default provider', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.available_models', [
        'gpt-4o',
        'gpt-4o-mini',
    ]);

    $this->get(route('chat.models'))
        ->assertOk()
        ->assertExactJson(['gpt-4o', 'gpt-4o-mini']);
});

test('returns empty list when the default provider has no curated models', function () {
    config()->set('ai.default', 'mistral');
    config()->set('ai.providers.mistral.available_models', null);

    $this->get(route('chat.models'))
        ->assertOk()
        ->assertExactJson([]);
});

test('delegates to OllamaClient when default provider is ollama', function () {
    config()->set('ai.default', 'ollama');
    config()->set('ai.providers.ollama.url', 'http://localhost:11434');

    Http::fake([
        'localhost:11434/api/tags' => Http::response([
            'models' => [
                ['name' => 'gemma4:e4b'],
                ['name' => 'llama3.1:8b'],
            ],
        ]),
    ]);

    $this->get(route('chat.models'))
        ->assertOk()
        ->assertExactJson(['gemma4:e4b', 'llama3.1:8b']);
});

test('filters non-string and empty entries from the configured list', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.available_models', [
        'gpt-4o',
        '',
        null,
        123,
        'gpt-4o-mini',
    ]);

    $this->get(route('chat.models'))
        ->assertOk()
        ->assertExactJson(['gpt-4o', 'gpt-4o-mini']);
});
