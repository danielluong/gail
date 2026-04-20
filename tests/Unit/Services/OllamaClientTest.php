<?php

use App\Enums\DocumentStatus;
use App\Services\OllamaClient;
use Illuminate\Support\Facades\Http;

test('returns sorted list of model names from the daemon', function () {
    Http::fake([
        'localhost:11434/api/tags' => Http::response([
            'models' => [
                ['name' => 'qwen2:7b'],
                ['name' => 'llama3.1:8b'],
                ['name' => 'bge-m3:latest'],
            ],
        ]),
    ]);

    $models = (new OllamaClient('http://localhost:11434'))->listModels();

    expect($models)->toBe(['bge-m3:latest', 'llama3.1:8b', 'qwen2:7b']);
});

test('returns empty array when the daemon is unreachable', function () {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    $models = (new OllamaClient('http://localhost:11434'))->listModels();

    expect($models)->toBe([]);
});

test('fromConfig reads url and timeout from ai.php', function () {
    config()->set('ai.providers.ollama.url', 'http://example.test:12345');

    $client = OllamaClient::fromConfig();

    Http::fake(['example.test:12345/api/tags' => Http::response(['models' => []])]);

    expect($client->listModels())->toBe([]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'example.test:12345'));
});

test('enum status casts remain stable across reads', function () {
    expect(DocumentStatus::Ready->value)->toBe('ready');
    expect(DocumentStatus::from('failed'))->toBe(DocumentStatus::Failed);
});
