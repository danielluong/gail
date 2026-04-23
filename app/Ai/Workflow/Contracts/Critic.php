<?php

namespace App\Ai\Workflow\Contracts;

use App\Ai\Workflow\Dto\CriticVerdict;

/**
 * Evaluates a pipeline's output and decides whether to approve it.
 * Deliberately a separate interface from {@see Agent} because the
 * semantics differ: a Critic never rewrites, it only judges — and the
 * orchestrator's retry loop branches on the structured verdict rather
 * than threading the critic's output into the next step.
 */
interface Critic
{
    /**
     * @param  array<string, mixed>  $data  the pipeline's output dict (query, response, research, …)
     */
    public function evaluate(array $data): CriticVerdict;
}
