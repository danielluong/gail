<?php

namespace App\Ai\Workflow\Kernel\Contracts;

use App\Ai\Workflow\Kernel\AgentKernel;

/**
 * Ordered composite of plugin names. The pipeline holds *names*, not
 * concrete instances — every step is resolved + dispatched through the
 * {@see AgentKernel} so nesting and tracing work uniformly. A pipeline
 * that bypasses the kernel (calls `$step->run()` directly) breaks the
 * "Kernel is the only orchestrator" rule.
 */
interface PipelinePlugin extends Plugin
{
    /**
     * Plugin names to dispatch in order. The Kernel threads each step's
     * `result` dict into the next step's input.
     *
     * @return list<string>
     */
    public function steps(): array;
}
