<?php

namespace App\Ai\Workflow\Retry;

use App\Ai\Workflow\Contracts\Agent;
use App\Ai\Workflow\Contracts\RetryStrategy;

/**
 * Default retry policy: discard the previous pipeline output entirely
 * and run a fresh pass with the Critic's feedback threaded into the
 * input. Used by every pipeline that doesn't have domain-specific merge
 * semantics (content, chat, etc.).
 */
final class ReplaceRetryStrategy implements RetryStrategy
{
    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $criticFeedback
     * @return array<string, mixed>
     */
    public function retry(Agent $pipeline, array $previous, array $criticFeedback): array
    {
        return $pipeline->run([
            'query' => (string) ($previous['query'] ?? ''),
            'critic_feedback' => $criticFeedback,
            'warnings' => $previous['warnings'] ?? [],
        ]);
    }
}
