<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use Generator;

/**
 * Task path: Generator produces a raw draft, Rewriter polishes it. Both
 * steps run sequentially via the Kernel — the pipeline never instantiates
 * its sub-steps directly, which is what makes nested tracing + per-step
 * dispatching work uniformly with the rest of the runtime.
 *
 * Streaming is synthetic: the underlying steps are synchronous prompt
 * calls (no live tool loops worth forwarding), so the streaming variant
 * runs them sync and emits a single `text_delta` frame carrying the
 * polished draft. Good enough for the chat UI to paint the answer;
 * actual token-by-token streaming would require step-level streaming.
 */
final class ContentPipelinePlugin implements StreamablePipelinePlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
    ) {}

    public function getName(): string
    {
        return 'content_pipeline';
    }

    public function steps(): array
    {
        return ['generator_step', 'rewriter_step'];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $threaded = $input;

        foreach ($this->steps() as $stepName) {
            $envelope = $this->kernel->dispatch($stepName, $threaded, $context);
            $threaded = [...$threaded, ...$envelope['result']];
        }

        return [
            'result' => $threaded,
            'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
        ];
    }

    public function stream(array $input, KernelContext $context): Generator
    {
        $envelope = $this->execute($input, $context);
        $result = $envelope['result'];
        $response = (string) ($result['response'] ?? '');

        if ($response !== '') {
            yield 'data: '.json_encode([
                'type' => 'text_delta',
                'delta' => $response,
            ])."\n\n";
        }

        return $result;
    }
}
