<?php

/*
|--------------------------------------------------------------------------
| Provider capability matrix
|--------------------------------------------------------------------------
|
| For each provider defined below, list the capabilities its driver
| implements in laravel/ai. This lets us derive every `default_for_*`
| value from the single `default` provider: if the default supports
| the capability, it's used; otherwise the slot stays null.
|
| Source of truth: the default*Model() methods on each driver in
| vendor/laravel/ai/src/Providers/. Keep this table in sync when the
| package adds capabilities upstream.
|
*/
$capabilities = [
    'anthropic' => ['text'],
    'azure' => ['text', 'embeddings'],
    'cohere' => ['embeddings', 'reranking'],
    'deepseek' => ['text'],
    'eleven' => ['audio', 'transcription'],
    'gemini' => ['text', 'images', 'embeddings'],
    'groq' => ['text'],
    'jina' => ['embeddings', 'reranking'],
    'mistral' => ['text', 'transcription', 'embeddings'],
    'ollama' => ['text', 'embeddings'],
    'openai' => ['text', 'images', 'audio', 'transcription', 'embeddings'],
    'openrouter' => ['text', 'embeddings'],
    'voyageai' => ['embeddings', 'reranking'],
    'xai' => ['text', 'images'],
];

$default = env('AI_DEFAULT_PROVIDER', 'ollama');

$for = fn (string $capability): ?string => in_array(
    $capability,
    $capabilities[$default] ?? [],
    true,
) ? $default : null;

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | `default` is the single source of truth — change it in `.env` via
    | `AI_DEFAULT_PROVIDER` and every `default_for_*` slot below is
    | derived automatically from the capability matrix above.
    |
    | Reranking stays null when the default is a text-first provider
    | (e.g. openai, ollama). Set a dedicated reranker via env override
    | below if you use one (cohere / jina / voyageai).
    |
    */

    'default' => $default,
    'default_for_images' => env('AI_DEFAULT_FOR_IMAGES', $for('images')),
    'default_for_audio' => env('AI_DEFAULT_FOR_AUDIO', $for('audio')),
    'default_for_transcription' => env('AI_DEFAULT_FOR_TRANSCRIPTION', $for('transcription')),
    'default_for_embeddings' => env('AI_DEFAULT_FOR_EMBEDDINGS', $for('embeddings')),
    'default_for_reranking' => env('AI_DEFAULT_FOR_RERANKING', $for('reranking')),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', 'ollama'),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'default_model' => env('OLLAMA_TEXT_MODEL_default', 'gemma4:e4b'),
            'models' => [
                'text' => [
                    'default' => env('OLLAMA_TEXT_MODEL_default', 'gemma4:e4b'),
                    'cheapest' => env('OLLAMA_TEXT_MODEL_CHEAPEST', 'gemma4:e4b'),
                    'smartest' => env('OLLAMA_TEXT_MODEL_SMARTEST', 'gemma4:31b'),
                ],
                'embeddings' => [
                    'default' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3:latest'),
                    'dimensions' => env('OLLAMA_EMBEDDING_DIMENSIONS', 1024),
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
            'available_models' => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4-turbo',
                'o1',
                'o1-mini',
                'o3-mini',
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
