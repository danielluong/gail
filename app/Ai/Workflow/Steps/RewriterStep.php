<?php

namespace App\Ai\Workflow\Steps;

use App\Ai\Agents\Content\RewriterAgent;
use App\Ai\Workflow\Contracts\Agent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow-layer adapter for {@see RewriterAgent}. Takes the raw draft
 * written to the context by {@see GeneratorStep} and asks the rewriter
 * for the polished final version. Populates `response` — the key the
 * orchestrator and Critic both read.
 *
 * If the upstream Generator failed and produced an empty draft, the
 * rewriter is skipped (nothing to polish) and a warning is emitted.
 */
class RewriterStep implements Agent
{
    /**
     * @param  array{query?: string, draft?: string, warnings?: list<string>, ...}  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $draft = (string) ($input['draft'] ?? '');
        $warnings = $input['warnings'] ?? [];

        if (trim($draft) === '') {
            $warnings[] = 'Rewriter skipped: upstream Generator produced no draft.';

            return [
                ...$input,
                'response' => '',
                'warnings' => $warnings,
            ];
        }

        $prompt = <<<PROMPT
        Original user request:
        {$query}

        Draft to rewrite:
        {$draft}

        Produce the final polished version as specified in your instructions.
        PROMPT;

        try {
            $response = RewriterAgent::make()->prompt($prompt);
            $polished = trim($response->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('universal.rewriter_failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Rewriter failed: '.$e->getMessage();
            $polished = $draft;
        }

        return [
            ...$input,
            'response' => $polished,
            'warnings' => $warnings,
        ];
    }
}
