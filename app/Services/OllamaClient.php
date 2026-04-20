<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Ollama HTTP API. Isolates provider URL/timeout
 * concerns from controllers so Ollama-specific behaviour can evolve (or be
 * swapped behind a provider interface) without touching HTTP layer code.
 */
class OllamaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public static function fromConfig(): self
    {
        $url = (string) config('ai.providers.ollama.url', 'http://localhost:11434');
        $timeout = (int) config('ai.providers.ollama.timeout', 5);

        return new self($url, $timeout > 0 ? $timeout : 5);
    }

    /**
     * Return a sorted list of installed model names. Returns an empty list
     * if the Ollama daemon is unreachable so the UI can render gracefully.
     *
     * @return list<string>
     */
    public function listModels(): array
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)->get($this->baseUrl.'/api/tags');

            return collect($response->json('models', []))
                ->pluck('name')
                ->filter()
                ->map(fn ($name) => (string) $name)
                ->sort()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
