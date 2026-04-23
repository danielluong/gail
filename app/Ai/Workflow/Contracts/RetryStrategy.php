<?php

namespace App\Ai\Workflow\Contracts;

use App\Actions\UniversalAssistant\RunUniversalAssistant;
use App\Ai\Workflow\Retry\MergeResearchRetryStrategy;
use App\Ai\Workflow\Retry\ReplaceRetryStrategy;

/**
 * Describes how the orchestrator produces the retry pass when the
 * {@see Critic} rejects a pipeline's output. Two strategies ship
 * in-tree:
 *
 * - {@see ReplaceRetryStrategy} — default;
 *   discards the first pass and runs the pipeline again with the
 *   Critic's feedback threaded into the input.
 * - {@see MergeResearchRetryStrategy} — does a
 *   surgical second Researcher pass + merges its findings with the
 *   first by topic, then re-runs the Editor. Used by the research path
 *   so nothing found on pass one is lost if the critic merely asks for
 *   more depth.
 *
 * Keeping this as a seam means the generic
 * {@see RunUniversalAssistant} can
 * absorb the research-specific merge semantics without splitting into
 * a separate orchestrator for research vs everything else.
 */
interface RetryStrategy
{
    /**
     * Produce the retry pass. The strategy decides whether to re-run
     * the whole pipeline (the default) or to call individual steps and
     * merge their output with the previous pass's.
     *
     * @param  array<string, mixed>  $previous  first-pass pipeline output
     * @param  array<string, mixed>  $criticFeedback  the Critic's verdict (same shape as {@see Critic::evaluate()})
     * @return array<string, mixed> the retry-pass pipeline output; the orchestrator will run the Critic on this
     */
    public function retry(Agent $pipeline, array $previous, array $criticFeedback): array;
}
