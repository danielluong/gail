<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\ChatStep;

/**
 * Kernel adapter for {@see ChatStep}. Wraps the casual-chat router
 * specialist used by the JSON universal-assistant fallback path. Threads
 * `critic_feedback` from the shared {@see KernelContext} into the Step
 * so retry passes get the verdict-augmented prompt.
 */
final class ChatStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly ChatStep $step,
    ) {}

    public function getName(): string
    {
        return 'chat_step';
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
