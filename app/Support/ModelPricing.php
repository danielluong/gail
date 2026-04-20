<?php

namespace App\Support;

class ModelPricing
{
    /**
     * Return the dollar cost of a single message's usage for the given
     * model, or null when the model has no pricing entry. Callers should
     * treat null as "unknown" — typically local Ollama runs — not zero,
     * so the UI can hide the cost rather than claim $0.00.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public function costFor(?string $model, ?array $usage): ?float
    {
        if ($model === null || $model === '' || $usage === null) {
            return null;
        }

        $prices = config("pricing.{$model}");

        if (! is_array($prices)) {
            return null;
        }

        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);

        if ($input === 0 && $output === 0) {
            return 0.0;
        }

        $inputRate = (float) ($prices['input'] ?? 0);
        $outputRate = (float) ($prices['output'] ?? 0);

        return ($input * $inputRate + $output * $outputRate) / 1_000_000;
    }
}
