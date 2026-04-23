<?php

namespace App\Ai\Workflow\Steps;

use App\Actions\Router\RunRouterExample;
use App\Ai\Agents\Router\ClassifierAgent;
use App\Ai\Support\JsonAgentCall;
use App\Ai\Workflow\Contracts\Agent;
use App\Enums\InputCategory;

/**
 * Workflow-layer adapter for {@see ClassifierAgent}. Converts the LLM
 * agent's prompt() call into the `run(array): array` contract, decoding
 * the strict-JSON reply via {@see JsonAgentCall::tryDecode} and
 * normalising category + confidence into safe types.
 *
 * Soft-fail policy mirrors {@see RunRouterExample}:
 * a broken classifier falls back to chat @ 0.0 confidence with a
 * warning, never blocks the orchestrator.
 */
class ClassifierStep implements Agent
{
    /**
     * @param  array{query?: string, warnings?: list<string>}  $input
     * @return array{query: string, category: string, confidence: float, warnings: list<string>}
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $warnings = $input['warnings'] ?? [];

        [$parsed, $warning] = JsonAgentCall::tryDecode(
            logTag: 'universal.classifier_failed',
            humanLabel: 'Classifier',
            call: fn () => ClassifierAgent::make()->prompt($query),
            logContext: ['query' => $query],
        );

        if ($parsed === null) {
            $warnings[] = $warning;

            return [
                'query' => $query,
                'category' => InputCategory::Chat->value,
                'confidence' => 0.0,
                'warnings' => $warnings,
            ];
        }

        $category = InputCategory::tryFromString((string) ($parsed['category'] ?? ''));

        if ($category === null) {
            $warnings[] = 'Classifier returned an unknown category; defaulting to chat.';
            $category = InputCategory::Chat;
        }

        $confidence = $this->normaliseConfidence($parsed['confidence'] ?? null, $warnings);

        return [
            'query' => $query,
            'category' => $category->value,
            'confidence' => $confidence,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function normaliseConfidence(mixed $raw, array &$warnings): float
    {
        if (! is_numeric($raw)) {
            $warnings[] = 'Classifier returned a non-numeric confidence; treating as 0.';

            return 0.0;
        }

        $value = (float) $raw;

        if ($value < 0.0 || $value > 1.0) {
            $warnings[] = 'Classifier returned a confidence outside [0, 1]; clamping.';

            return max(0.0, min(1.0, $value));
        }

        return $value;
    }
}
