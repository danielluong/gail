<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\GeneratorStep;

/**
 * Kernel adapter for {@see GeneratorStep}. Threads `critic_feedback`
 * from the shared {@see KernelContext} so the Generator can address
 * flagged gaps on a retry pass.
 */
final class GeneratorStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly GeneratorStep $step,
    ) {}

    public function getName(): string
    {
        return 'generator_step';
    }

    public function execute(array $input, KernelContext $context): array
    {
        $result = $this->step->run([
            ...$input,
            'critic_feedback' => $context->criticFeedback()?->toArray(),
        ]);

        return [
            'result' => $result,
            'meta' => ['plugin' => $this->getName(), 'type' => 'agent'],
        ];
    }
}
