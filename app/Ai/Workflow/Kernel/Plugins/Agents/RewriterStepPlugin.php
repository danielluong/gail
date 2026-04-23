<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\RewriterStep;

/**
 * Kernel adapter for {@see RewriterStep}. Pure pass-through — the
 * Rewriter only needs the upstream `draft` to polish, no Critic feedback
 * threading required.
 */
final class RewriterStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly RewriterStep $step,
    ) {}

    public function getName(): string
    {
        return 'rewriter_step';
    }

    public function execute(array $input, KernelContext $context): array
    {
        return [
            'result' => $this->step->run($input),
            'meta' => ['plugin' => $this->getName(), 'type' => 'agent'],
        ];
    }
}
