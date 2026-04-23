<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\RouterPipelinePlugin;
use App\Ai\Workflow\Kernel\Plugins\Routers\AgentTypeRouter;
use App\Ai\Workflow\Steps\ClassifierStep;

/**
 * Kernel adapter for {@see ClassifierStep}. Used by the
 * {@see AgentTypeRouter} when no
 * `agent_type` hint is present (the JSON universal-assistant flow), and
 * by the {@see RouterPipelinePlugin}
 * for its streaming classifier phase.
 */
final class ClassifierStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly ClassifierStep $step,
    ) {}

    public function getName(): string
    {
        return 'classifier_step';
    }

    public function execute(array $input, KernelContext $context): array
    {
        return [
            'result' => $this->step->run($input),
            'meta' => ['plugin' => $this->getName(), 'type' => 'agent'],
        ];
    }
}
