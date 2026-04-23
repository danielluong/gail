<?php

use App\Ai\Workflow\Dto\CriticVerdict;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\Contracts\CriticPlugin;
use App\Ai\Workflow\Kernel\Contracts\PipelinePlugin;
use App\Ai\Workflow\Kernel\Contracts\RouterPlugin;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\PluginRegistry;
use App\Ai\Workflow\Retry\ReplaceRetryStrategy;
use Illuminate\Container\Container;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Invariants that the upcoming DTO refactor must preserve. These assertions
 * pin down the *shape* of the orchestrator's public envelope — the things
 * a future refactor could silently widen or reorder without any existing
 * test noticing. Everything here is a regression anchor, not a new feature.
 */

function invariantRouter(string $target): RouterPlugin
{
    return new class($target) implements RouterPlugin
    {
        public function __construct(private readonly string $target) {}

        public function getName(): string
        {
            return 'agent_type_router';
        }

        public function select(array $input, KernelContext $context): string
        {
            return $this->target;
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => ['pipeline' => $this->target],
                'meta' => ['plugin' => 'agent_type_router', 'type' => 'router'],
            ];
        }
    };
}

function invariantPipeline(string $name, array $result = ['response' => 'ok']): PipelinePlugin
{
    return new class($name, $result) implements PipelinePlugin
    {
        public function __construct(
            private readonly string $name,
            private readonly array $result,
        ) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function steps(): array
        {
            return [];
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => $this->result,
                'meta' => ['plugin' => $this->name, 'type' => 'pipeline'],
            ];
        }
    };
}

function invariantCritic(): CriticPlugin
{
    return new class implements CriticPlugin
    {
        public function getName(): string
        {
            return 'default_critic';
        }

        public function evaluate(array $output, KernelContext $context): CriticVerdict
        {
            return new CriticVerdict(
                approved: true,
                issues: [],
                missing: [],
                missingTopics: [],
                improvementSuggestions: [],
                confidence: 'high',
                warnings: [],
            );
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => $this->evaluate($input, $context)->toArray(),
                'meta' => ['plugin' => 'default_critic', 'type' => 'critic'],
            ];
        }
    };
}

function invariantKernel(PluginRegistry $registry): AgentKernel
{
    return new AgentKernel(
        registry: $registry,
        defaultRetry: new ReplaceRetryStrategy,
    );
}

test('run returns a fixed-shape envelope that downstream consumers rely on', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('chat_pipeline'));
    $registry->register('chat_pipeline', invariantPipeline('chat_pipeline'));
    $registry->register('default_critic', invariantCritic());

    $result = invariantKernel($registry)->run('hi');

    expect(array_keys($result))->toBe(['output', 'pipeline', 'critic', 'trace', 'iterations']);
    expect($result['output'])->toBeArray();
    expect($result['pipeline'])->toBeString();
    expect($result['critic'])->toBeArray();
    expect($result['trace'])->toBeArray();
    expect($result['iterations'])->toBeInt();
});

test('dispatch returns a {result, meta} envelope and records one trace entry per call', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('solo', new class implements AgentPlugin
    {
        public function getName(): string
        {
            return 'solo';
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => ['response' => 'done'],
                'meta' => ['plugin' => 'solo', 'type' => 'agent'],
            ];
        }
    });

    $kernel = invariantKernel($registry);
    $context = new KernelContext('q');

    $envelope = $kernel->dispatch('solo', ['query' => 'q'], $context);

    expect(array_keys($envelope))->toBe(['result', 'meta']);
    expect($envelope['result'])->toBe(['response' => 'done']);
    expect($envelope['meta']['plugin'])->toBe('solo');
    expect($context->trace)->toHaveCount(1);
    expect($context->trace[0]['plugin'])->toBe('solo');
    expect($context->trace[0]['type'])->toBe('agent');
    expect($context->trace[0]['duration_ms'])->toBeFloat();
});

test('run sets selectedPipeline on the context after the router resolves', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('research_pipeline'));
    $registry->register('research_pipeline', invariantPipeline('research_pipeline'));
    $registry->register('default_critic', invariantCritic());

    $context = new KernelContext('q');
    invariantKernel($registry)->run('q', $context);

    expect($context->selectedPipeline)->toBe('research_pipeline');
});

test('run rejects a router plugin registered under the wrong contract', function () {
    $registry = new PluginRegistry(new Container);
    // Registering a plain AgentPlugin under the router slot — should fail.
    $registry->register('agent_type_router', new class implements AgentPlugin
    {
        public function getName(): string
        {
            return 'agent_type_router';
        }

        public function execute(array $input, KernelContext $context): array
        {
            return ['result' => [], 'meta' => ['plugin' => 'x', 'type' => 'agent']];
        }
    });

    expect(fn () => invariantKernel($registry)->run('q'))
        ->toThrow(RuntimeException::class, 'not a RouterPlugin');
});

test('run rejects a pipeline plugin registered under the wrong contract', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('bad_pipeline'));
    // Registering a plain AgentPlugin under the pipeline slot — should fail.
    $registry->register('bad_pipeline', new class implements AgentPlugin
    {
        public function getName(): string
        {
            return 'bad_pipeline';
        }

        public function execute(array $input, KernelContext $context): array
        {
            return ['result' => [], 'meta' => ['plugin' => 'bad_pipeline', 'type' => 'agent']];
        }
    });

    expect(fn () => invariantKernel($registry)->run('q'))
        ->toThrow(RuntimeException::class, 'not a PipelinePlugin');
});

test('run rejects a critic plugin registered under the wrong contract', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('chat_pipeline'));
    $registry->register('chat_pipeline', invariantPipeline('chat_pipeline'));
    $registry->register('default_critic', new class implements AgentPlugin
    {
        public function getName(): string
        {
            return 'default_critic';
        }

        public function execute(array $input, KernelContext $context): array
        {
            return ['result' => [], 'meta' => ['plugin' => 'default_critic', 'type' => 'agent']];
        }
    });

    expect(fn () => invariantKernel($registry)->run('q'))
        ->toThrow(RuntimeException::class, 'not a CriticPlugin');
});

test('stream falls back to a synthetic text_delta when the selected pipeline is not streamable', function () {
    // Only execute() exists — no StreamablePipelinePlugin. The kernel
    // must still yield a text_delta so the client gets content.
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('content_pipeline'));
    $registry->register('content_pipeline', invariantPipeline('content_pipeline', [
        'response' => 'sync answer',
    ]));
    $registry->register('default_critic', invariantCritic());

    $context = new KernelContext('q');
    $phaseFrames = [];
    $context->set('yieldPhase', function (array $phase) use (&$phaseFrames): string {
        $phaseFrames[] = $phase;

        return 'data: '.json_encode(['type' => 'phase', ...$phase])."\n\n";
    });

    $frames = iterator_to_array(
        invariantKernel($registry)->stream('q', $context),
        preserve_keys: false,
    );

    // First frame must be the synthetic text_delta with the pipeline's response.
    $firstPayload = json_decode(substr($frames[0], 6), true);
    expect($firstPayload['type'])->toBe('text_delta');
    expect($firstPayload['delta'])->toBe('sync answer');
    // Then critic running + complete phase frames.
    expect($phaseFrames)->toHaveCount(2);
    expect($phaseFrames[0]['status'])->toBe('running');
    expect($phaseFrames[1]['status'])->toBe('complete');
});

test('stream skips the synthetic text_delta when the sync fallback response is empty', function () {
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('empty_pipeline'));
    $registry->register('empty_pipeline', invariantPipeline('empty_pipeline', ['response' => '']));

    $frames = iterator_to_array(
        invariantKernel($registry)->stream('q', withCritic: false),
        preserve_keys: false,
    );

    expect($frames)->toBe([]);
});

test('stream runs without a yieldPhase closure in context — critic evaluation stays silent', function () {
    // The chat UI provides yieldPhase; other streaming consumers may not.
    // Streaming must still run the critic and return the verdict via
    // Generator::getReturn() even when no phase frames can be yielded.
    $registry = new PluginRegistry(new Container);
    $registry->register('agent_type_router', invariantRouter('streaming_pipeline'));
    $registry->register('streaming_pipeline', new class implements StreamablePipelinePlugin
    {
        public function getName(): string
        {
            return 'streaming_pipeline';
        }

        public function steps(): array
        {
            return [];
        }

        public function execute(array $input, KernelContext $context): array
        {
            return ['result' => ['response' => 'x'], 'meta' => ['plugin' => 'streaming_pipeline', 'type' => 'pipeline']];
        }

        public function stream(array $input, KernelContext $context): Generator
        {
            yield "data: chunk\n\n";

            return ['response' => 'x'];
        }
    });
    $registry->register('default_critic', invariantCritic());

    // No yieldPhase on the context — emulates research/router JSON callers.
    $generator = invariantKernel($registry)->stream('q');
    $frames = iterator_to_array($generator, preserve_keys: false);
    $envelope = $generator->getReturn();

    expect($frames)->toBe(["data: chunk\n\n"]);
    expect($envelope['critic']['approved'])->toBeTrue();
    expect($envelope['pipeline'])->toBe('streaming_pipeline');
});

test('stream skips the critic entirely when withCritic is false', function () {
    $registry = new PluginRegistry(new Container);
    $criticCalls = 0;
    $critic = new class($criticCalls) implements CriticPlugin
    {
        public function __construct(private int &$calls) {}

        public function getName(): string
        {
            return 'default_critic';
        }

        public function evaluate(array $output, KernelContext $context): CriticVerdict
        {
            $this->calls++;

            return new CriticVerdict(
                approved: true,
                issues: [],
                missing: [],
                missingTopics: [],
                improvementSuggestions: [],
                confidence: 'high',
                warnings: [],
            );
        }

        public function execute(array $input, KernelContext $context): array
        {
            return ['result' => $this->evaluate($input, $context)->toArray(), 'meta' => ['plugin' => 'default_critic', 'type' => 'critic']];
        }
    };

    $registry->register('agent_type_router', invariantRouter('p'));
    $registry->register('p', invariantPipeline('p'));
    $registry->register('default_critic', $critic);

    $generator = invariantKernel($registry)->stream('q', withCritic: false);
    iterator_to_array($generator, preserve_keys: false);

    expect($criticCalls)->toBe(0);
    expect($generator->getReturn()['critic'])->toBeNull();
});
