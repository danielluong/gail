<?php

namespace App\Ai\Workflow\Kernel\Plugins\Routers;

use App\Ai\Workflow\Contracts\Router;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\RouterPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Routing\UniversalRouter;
use App\Ai\Workflow\Steps\ClassifierStep;
use BackedEnum;
use RuntimeException;

/**
 * The single router plugin for every flow in the system. Two modes
 * keyed by whether the caller supplied an explicit `agent_type` hint
 * in the {@see KernelContext}:
 *
 * **Mode A (chat UI streaming).** The controller knows which agent
 * the user picked (Default / Limerick / MySQL / Research / Router).
 * That value is set on `context.metadata['agent_type']` and we map it
 * directly to a pipeline name — no LLM call.
 *
 * **Mode B (JSON universal-assistant).** No `agent_type` hint, so we
 * dispatch the {@see ClassifierStep} through
 * the Kernel and feed its verdict to the existing pure-PHP
 * {@see Router} (currently {@see UniversalRouter})
 * to apply the confidence floor + path mapping. The classifier is
 * dispatched via the kernel rather than instantiated directly so the
 * trace records it under its own plugin name.
 *
 * The router's only responsibility is to return a pipeline plugin
 * name. Actual execution belongs to the Kernel.
 */
final class AgentTypeRouter implements RouterPlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
        private readonly Router $router,
    ) {}

    public function getName(): string
    {
        return 'agent_type_router';
    }

    public function select(array $input, KernelContext $context): string
    {
        $agentType = $context->agentType();

        if ($agentType !== null) {
            return $agentType->pipelinePluginName();
        }

        if ($context->has(KernelContext::KEY_AGENT_TYPE)) {
            // A raw value was stashed but couldn't be coerced to the
            // enum. Preserve the original error surface for callers
            // that rely on the "unknown agent_type" message.
            $raw = $context->get(KernelContext::KEY_AGENT_TYPE);
            $value = $raw instanceof BackedEnum ? $raw->value : (string) $raw;

            throw new RuntimeException("Unknown agent_type [{$value}]");
        }

        $envelope = $this->kernel->dispatch('classifier_step', $input, $context);
        $classification = $envelope['result'];
        $context->setClassification($classification);

        return match ($this->router->route($classification)) {
            'research' => 'research_pipeline',
            'content' => 'content_pipeline',
            default => 'chat_specialist_pipeline',
        };
    }

    public function execute(array $input, KernelContext $context): array
    {
        return [
            'result' => ['pipeline' => $this->select($input, $context)],
            'meta' => ['plugin' => $this->getName(), 'type' => 'router'],
        ];
    }
}
