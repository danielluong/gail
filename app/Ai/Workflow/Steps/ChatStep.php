<?php

namespace App\Ai\Workflow\Steps;

use App\Ai\Agents\Router\ChatAgent;
use App\Ai\Workflow\Contracts\Agent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Workflow-layer adapter for {@see ChatAgent} (the Router specialist —
 * short, friendly, tool-free). Used for casual-conversation inputs and
 * as the low-confidence fallback when the classifier can't commit to a
 * category.
 *
 * Unlike the research / content pipelines this is a single-step path
 * (no upstream agent produces a `research` or `draft` key to consume),
 * which is why the orchestrator's path map holds a bare Agent here
 * rather than a Pipeline — both implement the same interface.
 */
class ChatStep implements Agent
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
            $response = ChatAgent::make()->prompt($augmented);
            $text = trim($response->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('universal.chat_failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Chat specialist failed: '.$e->getMessage();
            $text = '';
        }

        return [
            ...$input,
            'response' => $text,
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

        $issues = is_array($feedback['issues'] ?? null) ? $feedback['issues'] : [];

        $notes = array_values(array_filter(
            $issues,
            fn ($v) => is_string($v) && trim($v) !== '',
        ));

        if ($notes === []) {
            return $original;
        }

        $bullets = implode("\n- ", $notes);

        return "{$original}\n\nThe previous reply was flagged for:\n- {$bullets}\n\nAddress these and reply again.";
    }
}
