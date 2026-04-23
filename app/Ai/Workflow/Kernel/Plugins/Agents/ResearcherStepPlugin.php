<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\ResearcherStep;

/**
 * Kernel adapter for {@see ResearcherStep}. The Step still owns the LLM
 * call + JSON normalisation; this plugin wraps the result in the Kernel's
 * `{result, meta}` envelope and threads `critic_feedback` from the
 * shared {@see KernelContext} into the Step's input on retry passes.
 */
final class ResearcherStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly ResearcherStep $step,
    ) {}

    public function getName(): string
    {
        return 'researcher_step';
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
