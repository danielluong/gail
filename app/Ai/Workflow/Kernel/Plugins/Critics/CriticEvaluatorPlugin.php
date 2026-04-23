<?php

namespace App\Ai\Workflow\Kernel\Plugins\Critics;

use App\Ai\Workflow\Critics\CriticAgentEvaluator;
use App\Ai\Workflow\Dto\CriticVerdict;
use App\Ai\Workflow\Kernel\Contracts\CriticPlugin;
use App\Ai\Workflow\Kernel\KernelContext;

/**
 * Kernel adapter for {@see CriticAgentEvaluator}. The underlying
 * evaluator already returns the canonical {@see CriticVerdict} —
 * this plugin only normalises the input keys (the kernel passes the
 * entire pipeline `result` dict; the Critic only needs
 * `query`/`response`/`research`).
 *
 * {@see execute()} hands back an array-shaped envelope so the kernel's
 * generic dispatch loop can treat every plugin identically; callers
 * that want the typed verdict go through {@see evaluate()} instead.
 */
final class CriticEvaluatorPlugin implements CriticPlugin
{
    public function __construct(
        private readonly CriticAgentEvaluator $evaluator,
    ) {}

    public function getName(): string
    {
        return 'default_critic';
    }

    public function evaluate(array $output, KernelContext $context): CriticVerdict
    {
        return $this->evaluator->evaluate([
            'query' => (string) ($output['query'] ?? $context->originalInput),
            'response' => (string) ($output['response'] ?? ''),
            'research' => $output['research'] ?? null,
        ]);
    }

    public function execute(array $input, KernelContext $context): array
    {
        return [
            'result' => $this->evaluate($input, $context)->toArray(),
            'meta' => ['plugin' => $this->getName(), 'type' => 'critic'],
        ];
    }
}
