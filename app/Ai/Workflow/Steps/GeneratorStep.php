<?php

namespace App\Ai\Workflow\Steps;

use App\Ai\Agents\Content\GeneratorAgent;
use App\Ai\Workflow\Contracts\Agent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow-layer adapter for {@see GeneratorAgent}. Produces a raw
 * draft for a task request, optionally augmenting the prompt with
 * Critic feedback on a retry pass so the next draft actually addresses
 * the flagged gaps.
 *
 * Writes to `draft` rather than `response` because the {@see RewriterStep}
 * downstream is the one that emits the final user-facing text. Keeping
 * the two keys distinct means the Critic can, if needed, inspect either
 * the raw draft or the polished rewrite without ambiguity.
 */
class GeneratorStep implements Agent
{
    /**
     * @param  array{query?: string, critic_feedback?: array<string, mixed>, warnings?: list<string>}  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $warnings = $input['warnings'] ?? [];
        $augmented = $this->augmentQuery($query, $input['critic_feedback'] ?? null);

        try {
            $response = GeneratorAgent::make()->prompt($augmented);
            $draft = trim($response->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('universal.generator_failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Generator failed: '.$e->getMessage();
            $draft = '';
        }

        return [
            ...$input,
            'draft' => $draft,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $feedback
     */
    private function augmentQuery(string $original, ?array $feedback): string
    {
        if ($feedback === null) {
            return $original;
        }

        $missing = is_array($feedback['missing'] ?? null) ? $feedback['missing'] : [];
        $issues = is_array($feedback['issues'] ?? null) ? $feedback['issues'] : [];

        $extras = array_values(array_filter(
            array_merge($missing, $issues),
            fn ($v) => is_string($v) && trim($v) !== '',
        ));

        if ($extras === []) {
            return $original;
        }

        $bullets = implode("\n- ", $extras);

        return "{$original}\n\nThe previous draft was rejected on review. Address these specific points in this attempt:\n- {$bullets}";
    }
}
