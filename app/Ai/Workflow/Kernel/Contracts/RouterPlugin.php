<?php

namespace App\Ai\Workflow\Kernel\Contracts;

use App\Ai\Workflow\Kernel\KernelContext;

/**
 * Selection-only contract: returns the name of the pipeline the Kernel
 * should dispatch next. Routers MUST NOT execute anything beyond their
 * own decision logic — no LLM calls in `select()` unless the decision
 * itself requires classification (and even then, the router calls the
 * classifier through the Kernel rather than instantiating it).
 *
 * Kept narrow so every routing rule can be exercised by unit tests
 * without mocking pipeline execution.
 */
interface RouterPlugin extends Plugin
{
    /**
     * @param  array<string, mixed>  $input
     * @return string name of the {@see PipelinePlugin} to dispatch
     */
    public function select(array $input, KernelContext $context): string;
}
