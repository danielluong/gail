<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Agents\BaseAgent;
use App\Ai\Conversations\RemembersConversations;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Pipelines\SingleAgentPipeline;
use Generator;
use RuntimeException;

/**
 * Generic 1-step pipeline for single-agent chat flows (Default chat,
 * Limerick, MySQL). The actual {@see BaseAgent} lives on the calling
 * action and is passed through the {@see KernelContext} as `facade` —
 * the chat UI's per-request agent owns conversation persistence via
 * {@see RemembersConversations}, so a singleton
 * pipeline instance can't bind to it at registration time.
 *
 * The plugin's only job is to lift the existing
 * {@see SingleAgentPipeline} (which already enforces the streaming
 * contract + soft-fail policy) into the Kernel's `{result, meta}`
 * envelope.
 */
final class SingleAgentPipelinePlugin implements StreamablePipelinePlugin
{
    public function getName(): string
    {
        return 'single_agent_pipeline';
    }

    /**
     * Single-agent pipelines have no nameable sub-steps — the agent IS
     * the step. Returning an empty list keeps the {@see PipelinePlugin}
     * contract honest while signalling "atomic from the kernel's view".
     */
    public function steps(): array
    {
        return [];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $pipeline = new SingleAgentPipeline($this->facade($context));

        return [
            'result' => $pipeline->run([
                ...$input,
                'attachments' => $context->attachments(),
                'model' => $context->modelOverride(),
            ]),
            'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
        ];
    }

    public function stream(array $input, KernelContext $context): Generator
    {
        $pipeline = new SingleAgentPipeline($this->facade($context));

        return yield from $pipeline->stream([
            ...$input,
            'attachments' => $context->attachments(),
            'model' => $context->modelOverride(),
        ]);
    }

    private function facade(KernelContext $context): BaseAgent
    {
        $facade = $context->facade();

        if ($facade === null) {
            throw new RuntimeException('single_agent_pipeline requires a BaseAgent in context.facade.');
        }

        return $facade;
    }
}
