<?php

namespace App\Ai\Workflow\Kernel\Contracts;

use App\Ai\Workflow\Dto\CriticVerdict;
use App\Ai\Workflow\Kernel\KernelContext;

/**
 * Evaluation-only contract. A Critic returns a structured verdict and
 * never rewrites the pipeline's output — the Kernel decides what to do
 * with the verdict (return as-is on approval, or trigger one retry pass
 * with the verdict written into `KernelContext::$metadata['critic_feedback']`).
 *
 * Kept separate from {@see AgentPlugin} because the semantics differ:
 * agents thread their result into the next step, critics branch the
 * orchestrator's control flow.
 */
interface CriticPlugin extends Plugin
{
    /**
     * @param  array<string, mixed>  $output  the pipeline's final result dict
     */
    public function evaluate(array $output, KernelContext $context): CriticVerdict;
}
