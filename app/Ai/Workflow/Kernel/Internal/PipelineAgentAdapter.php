<?php

namespace App\Ai\Workflow\Kernel\Internal;

use App\Ai\Workflow\Contracts\Agent;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\PipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;

/**
 * Wraps a {@see PipelinePlugin} behind the workflow-level {@see Agent}
 * interface so retry strategies (which predate the plugin model and
 * operate on the narrower Agent shape) can drive kernel-routed
 * dispatch without learning about plugins.
 *
 * Intentionally scoped to `Kernel\Internal\` — the adapter exists
 * solely to bridge the two contracts during a retry pass and has no
 * value as a public extension point. The Kernel is the only legitimate
 * constructor.
 */
final class PipelineAgentAdapter implements Agent
{
    public function __construct(
        private readonly AgentKernel $kernel,
        private readonly PipelinePlugin $pipeline,
        private readonly KernelContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        return $this->kernel->dispatch($this->pipeline->getName(), $input, $this->context)['result'];
    }
}
