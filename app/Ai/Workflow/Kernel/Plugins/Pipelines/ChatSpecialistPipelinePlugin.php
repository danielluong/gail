<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Agents\ChatAgent;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\PipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\ChatStep;

/**
 * One-step pipeline wrapping the casual-chat router specialist
 * ({@see ChatStep}, which uses
 * {@see \App\Ai\Agents\Router\ChatAgent}). Used by the JSON
 * universal-assistant flow as the low-confidence-classification fallback
 * and as the default `chat`-category target.
 *
 * Distinct from {@see SingleAgentPipelinePlugin}, which wraps the chat
 * UI's main {@see ChatAgent} per-request via the facade —
 * a different agent class with its own tools and conversation persistence.
 */
final class ChatSpecialistPipelinePlugin implements PipelinePlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
    ) {}

    public function getName(): string
    {
        return 'chat_specialist_pipeline';
    }

    public function steps(): array
    {
        return ['chat_step'];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $envelope = $this->kernel->dispatch('chat_step', $input, $context);

        return [
            'result' => [...$input, ...$envelope['result']],
            'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
        ];
    }
}
