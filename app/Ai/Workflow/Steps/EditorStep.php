<?php

namespace App\Ai\Workflow\Steps;

use App\Ai\Agents\Research\EditorAgent;
use App\Ai\Workflow\Contracts\Agent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow-layer adapter for {@see EditorAgent}. Reads the structured
 * research from the input context (populated by {@see ResearcherStep}
 * upstream) and asks the Editor for a polished markdown answer.
 *
 * The Editor itself is tool-free by contract — this step enforces that
 * by only passing pre-gathered findings, never raw user intent to
 * research further.
 */
class EditorStep implements Agent
{
    /**
     * @param  array{query?: string, research?: array<string, mixed>, warnings?: list<string>, ...}  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $research = is_array($input['research'] ?? null) ? $input['research'] : [];
        $warnings = $input['warnings'] ?? [];

        $researchJson = json_encode($research, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
        User question:
        {$query}

        Research findings (JSON):
        {$researchJson}

        Produce the final markdown answer as specified in your instructions.
        Use ONLY information from the findings above.
        PROMPT;

        try {
            $response = EditorAgent::make()->prompt($prompt);
            $answer = trim($response->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('universal.editor_failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Editor failed: '.$e->getMessage();
            $answer = "## Summary\n\nThe research pipeline could not produce an answer: {$e->getMessage()}.";
        }

        return [
            ...$input,
            'response' => $answer,
            'warnings' => $warnings,
        ];
    }
}
