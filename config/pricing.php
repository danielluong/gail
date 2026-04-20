<?php

/*
 * Per-model token prices in USD per 1M tokens. Cost is computed as
 * (prompt_tokens * input + completion_tokens * output) / 1_000_000.
 *
 * Models absent from this list render no cost. Operators should copy the
 * provider's current prices on ingestion and bump them as rates change.
 */

return [
    // Anthropic
    'claude-opus-4-7' => ['input' => 15.0, 'output' => 75.0],
    'claude-opus-4-6' => ['input' => 15.0, 'output' => 75.0],
    'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
    'claude-haiku-4-5-20251001' => ['input' => 1.0, 'output' => 5.0],

    // OpenAI
    'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
    'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6],
    'gpt-4-turbo' => ['input' => 10.0, 'output' => 30.0],
    'o1' => ['input' => 15.0, 'output' => 60.0],
    'o1-mini' => ['input' => 1.1, 'output' => 4.4],
    'o3-mini' => ['input' => 1.1, 'output' => 4.4],
];
