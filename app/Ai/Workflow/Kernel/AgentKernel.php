<?php

namespace App\Ai\Workflow\Kernel;

use App\Ai\Workflow\Contracts\Agent;
use App\Ai\Workflow\Contracts\RetryStrategy;
use App\Ai\Workflow\Dto\CriticVerdict;
use App\Ai\Workflow\Kernel\Contracts\CriticPlugin;
use App\Ai\Workflow\Kernel\Contracts\PipelinePlugin;
use App\Ai\Workflow\Kernel\Contracts\RouterPlugin;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\Internal\PipelineAgentAdapter;
use App\Ai\Workflow\Retry\ReplaceRetryStrategy;
use Generator;
use RuntimeException;

/**
 * Central runtime for every agent flow in the app. The only orchestrator —
 * after migration, no other class calls `$pipeline->run()` or
 * `$step->run()` directly. Two entry points share one execution model:
 *
 *   {@see run()}    sync: Router → Pipeline → Critic → (one retry?)  → final dict
 *   {@see stream()} SSE:  Router → Pipeline (streamed) → Critic phase frame
 *
 * **Sync vs streaming retry asymmetry — deliberate.**
 *
 * Sync mode does the one-shot retry per the kernel spec: rewrite the
 * Critic's verdict into `KernelContext::$metadata['critic_feedback']`,
 * dispatch the same pipeline again (delegating to its registered
 * {@see RetryStrategy} when one exists, so research keeps its merge
 * semantics), re-evaluate.
 *
 * Streaming mode emits the Critic verdict as a phase frame but does not
 * auto-retry. That matches the existing chat UX policy — re-rendering
 * an answer after the user already saw the first one is jarring; the
 * regenerate button covers retries.
 */
final class AgentKernel
{
    /**
     * @param  array<string, RetryStrategy>  $retryStrategies  keyed by pipeline plugin name
     */
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly ReplaceRetryStrategy $defaultRetry,
        private readonly array $retryStrategies = [],
        private readonly string $defaultRouter = 'agent_type_router',
        private readonly string $defaultCritic = 'default_critic',
    ) {}

    /**
     * @return array{
     *   output: array<string, mixed>,
     *   pipeline: string,
     *   critic: array<string, mixed>|null,
     *   trace: list<array{plugin: string, type: string, duration_ms: float}>,
     *   iterations: int,
     * }
     */
    public function run(
        string $input,
        ?KernelContext $context = null,
        ?string $router = null,
        ?string $critic = null,
        bool $withCritic = true,
    ): array {
        $context ??= new KernelContext($input);
        $pipelineName = $this->selectPipeline($input, $context, $router);
        $pipeline = $this->resolvePipeline($pipelineName);

        $result = $this->dispatch($pipelineName, ['query' => $input], $context)['result'];
        $iterations = 1;
        $verdict = null;

        if ($withCritic) {
            $criticPlugin = $this->resolveCritic($critic);
            $verdict = $this->runCritic($criticPlugin, $result, $context);

            if (! $verdict->approved && $context->retryCount === 0) {
                $context->retryCount = 1;
                // Stash the typed verdict; the setter serializes it so
                // soft-typed step dicts read `critic_feedback.missing`
                // etc without rehydrating the DTO.
                $context->setCriticFeedback($verdict);
                $result = $this->retry($pipeline, $result, $verdict->toArray(), $context);
                $verdict = $this->runCritic($criticPlugin, $result, $context);
                $iterations = 2;
            }
        }

        return [
            'output' => $result,
            'pipeline' => $pipelineName,
            'critic' => $verdict?->toArray(),
            'trace' => $context->trace,
            'iterations' => $iterations,
        ];
    }

    /**
     * Streaming entry point. Yields already-framed SSE strings; the
     * final context dict + critic verdict are returned via
     * `Generator::getReturn()` for callers that need to post-process
     * (e.g. persist tool activity).
     *
     * @return Generator<int, string, mixed, array{output: array<string, mixed>, pipeline: string, critic: array<string, mixed>|null, trace: list<array{plugin: string, type: string, duration_ms: float}>}>
     */
    public function stream(
        string $input,
        ?KernelContext $context = null,
        ?string $router = null,
        ?string $critic = null,
        bool $withCritic = true,
    ): Generator {
        $context ??= new KernelContext($input);
        $pipelineName = $this->selectPipeline($input, $context, $router);
        $pipeline = $this->resolvePipeline($pipelineName);
        $stepInput = ['query' => $input];

        if ($pipeline instanceof StreamablePipelinePlugin) {
            $generator = $pipeline->stream($stepInput, $context);
            $start = microtime(true);

            foreach ($generator as $frame) {
                yield $frame;
            }

            $context->recordTrace($pipelineName, 'pipeline', (microtime(true) - $start) * 1000);
            $result = $generator->getReturn();
        } else {
            $result = $this->dispatch($pipelineName, $stepInput, $context)['result'];

            $response = (string) ($result['response'] ?? '');

            if ($response !== '') {
                yield 'data: '.json_encode([
                    'type' => 'text_delta',
                    'delta' => $response,
                ])."\n\n";
            }
        }

        $verdict = null;

        if ($withCritic) {
            $yieldPhase = $context->yieldPhase();

            if ($yieldPhase !== null) {
                yield $yieldPhase([
                    'key' => 'critic',
                    'label' => 'Reviewing',
                    'status' => 'running',
                ]);
            }

            $criticPlugin = $this->resolveCritic($critic);
            $verdict = $this->runCritic($criticPlugin, $result, $context);
            $result['critic'] = $verdict->toArray();

            if ($yieldPhase !== null) {
                yield $yieldPhase([
                    'key' => 'critic',
                    'label' => 'Reviewing',
                    'status' => 'complete',
                    'approved' => $verdict->approved,
                    'confidence' => $verdict->confidence,
                    'issues' => $verdict->issues,
                    'missing_topics' => $verdict->missingTopics,
                ]);
            }
        }

        return [
            'output' => $result,
            'pipeline' => $pipelineName,
            'critic' => $verdict?->toArray(),
            'trace' => $context->trace,
        ];
    }

    /**
     * Resolve any plugin by name, time it, record a trace entry, and
     * return its standard `{result, meta}` envelope. PipelinePlugins
     * iterate their `steps()` and call this method for each step —
     * that recursion is what makes the trace nest naturally.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, meta: array{plugin: string, type: string}}
     */
    public function dispatch(string $pluginName, array $input, KernelContext $context): array
    {
        $plugin = $this->registry->resolve($pluginName);
        $start = microtime(true);

        $envelope = $plugin->execute($input, $context);

        $type = $envelope['meta']['type'] ?? 'agent';
        $context->recordTrace($pluginName, $type, (microtime(true) - $start) * 1000);

        return $envelope;
    }

    private function selectPipeline(string $input, KernelContext $context, ?string $routerName): string
    {
        $routerName ??= $this->defaultRouter;
        $router = $this->registry->resolve($routerName);

        if (! $router instanceof RouterPlugin) {
            throw new RuntimeException("Plugin [{$routerName}] is not a RouterPlugin.");
        }

        $start = microtime(true);
        $pipelineName = $router->select(['query' => $input], $context);
        $context->recordTrace($routerName, 'router', (microtime(true) - $start) * 1000);
        $context->selectedPipeline = $pipelineName;

        return $pipelineName;
    }

    private function resolvePipeline(string $name): PipelinePlugin
    {
        $pipeline = $this->registry->resolve($name);

        if (! $pipeline instanceof PipelinePlugin) {
            throw new RuntimeException("Plugin [{$name}] is not a PipelinePlugin.");
        }

        return $pipeline;
    }

    private function resolveCritic(?string $name): CriticPlugin
    {
        $name ??= $this->defaultCritic;
        $plugin = $this->registry->resolve($name);

        if (! $plugin instanceof CriticPlugin) {
            throw new RuntimeException("Plugin [{$name}] is not a CriticPlugin.");
        }

        return $plugin;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function runCritic(CriticPlugin $critic, array $result, KernelContext $context): CriticVerdict
    {
        $start = microtime(true);
        $verdict = $critic->evaluate($result, $context);
        $context->recordTrace($critic->getName(), 'critic', (microtime(true) - $start) * 1000);

        return $verdict;
    }

    /**
     * Single retry pass. Defers to a pipeline-specific
     * {@see RetryStrategy} (e.g. research's merge semantics) when one
     * is registered; otherwise falls back to the default replace
     * strategy. The pipeline is wrapped in a
     * {@see PipelineAgentAdapter} so strategies — which operate on the
     * narrower workflow {@see Agent} shape —
     * still drive kernel-routed dispatch.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    private function retry(PipelinePlugin $pipeline, array $previous, array $verdict, KernelContext $context): array
    {
        $strategy = $this->retryStrategies[$pipeline->getName()] ?? $this->defaultRetry;

        return $strategy->retry(
            new PipelineAgentAdapter($this, $pipeline, $context),
            $previous,
            $verdict,
        );
    }
}
