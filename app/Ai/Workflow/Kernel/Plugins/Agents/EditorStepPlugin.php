<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\EditorStep;

/**
 * Kernel adapter for {@see EditorStep}. The Step is purely transformative
 * (research dict → markdown answer) — no Critic feedback to thread, so
 * this is a literal pass-through wrapper.
 */
final class EditorStepPlugin implements AgentPlugin
{
    public function __construct(
        private readonly EditorStep $step,
    ) {}

    public function getName(): string
    {
        return 'editor_step';
    }

    public function execute(array $input, KernelContext $context): array
    {
        return [
            'result' => $this->step->run($input),
            'meta' => ['plugin' => $this->getName(), 'type' => 'agent'],
        ];
    }
}
