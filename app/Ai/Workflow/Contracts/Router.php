<?php

namespace App\Ai\Workflow\Contracts;

/**
 * Pure-PHP dispatcher that maps a classification result to a pipeline /
 * agent key. No LLM calls — the classifier (an Agent) runs upstream and
 * hands its JSON verdict to this router, which applies deterministic
 * policy (confidence floors, category → path mapping) and returns a
 * string the orchestrator can look up in its `string => Agent` map.
 *
 * Kept narrow so every routing rule can be exercised by unit tests
 * without mocking anything.
 */
interface Router
{
    /**
     * @param  array{category?: string, confidence?: float|int, ...}  $classification
     */
    public function route(array $classification): string;
}
