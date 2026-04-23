<?php

use App\Ai\Workflow\Contracts\Agent;
use App\Ai\Workflow\Contracts\RetryStrategy;
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

function staticRouter(string $pipelineName): RouterPlugin
{
    return new class($pipelineName) implements RouterPlugin
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
                'meta' => ['plugin' => $this->getName(), 'type' => 'router'],
            ];
        }
    };
}

function fakeAgent(string $name, callable $handler): AgentPlugin
{
    return new class($name, $handler) implements AgentPlugin
    {
        /** @param callable(array<string, mixed>, KernelContext): array<string, mixed> $handler */
        public function __construct(
            private readonly string $name,
            private $handler,
        ) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => ($this->handler)($input, $context),
                'meta' => ['plugin' => $this->name, 'type' => 'agent'],
            ];
        }
    };
}

function delegatingPipeline(string $name, array $stepNames, AgentKernel $kernel): PipelinePlugin
{
    return new class($name, $stepNames, $kernel) implements PipelinePlugin
    {
        public function __construct(
            private readonly string $name,
            private readonly array $stepNames,
            private readonly AgentKernel $kernel,
        ) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function steps(): array
        {
            return $this->stepNames;
        }

        public function execute(array $input, KernelContext $context): array
        {
            $threaded = $input;

            foreach ($this->stepNames as $stepName) {
                $envelope = $this->kernel->dispatch($stepName, $threaded, $context);
                $threaded = [...$threaded, ...$envelope['result']];
            }

            return [
                'result' => $threaded,
                'meta' => ['plugin' => $this->name, 'type' => 'pipeline'],
            ];
        }
    };
}

function staticCritic(bool $approved, array $extra = []): CriticPlugin
{
    return new class($approved, $extra) implements CriticPlugin
    {
        public function __construct(
            private readonly bool $approved,
            private readonly array $extra,
        ) {}

        public function getName(): string
        {
            return 'default_critic';
        }

        public function evaluate(array $output, KernelContext $context): CriticVerdict
        {
            return new CriticVerdict(
                approved: $this->approved,
                issues: $this->extra['issues'] ?? [],
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
                'meta' => ['plugin' => $this->getName(), 'type' => 'critic'],
            ];
        }
    };
}

function makeKernel(PluginRegistry $registry, array $retryStrategies = []): AgentKernel
{
    return new AgentKernel(
        registry: $registry,
        defaultRetry: new ReplaceRetryStrategy,
        retryStrategies: $retryStrategies,
    );
}

test('run dispatches Router → Pipeline → Critic and records a trace per plugin', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $registry->register('agent_type_router', staticRouter('chat_pipeline'));
    $registry->register('chat_step', fakeAgent('chat_step', fn (array $input) => [
        'response' => "echo: {$input['query']}",
    ]));
    $registry->register('chat_pipeline', delegatingPipeline('chat_pipeline', ['chat_step'], $kernel));
    $registry->register('default_critic', staticCritic(approved: true));

    $result = $kernel->run('hello');

    expect($result['pipeline'])->toBe('chat_pipeline');
    expect($result['output']['response'])->toBe('echo: hello');
    expect($result['critic']['approved'])->toBeTrue();
    expect($result['iterations'])->toBe(1);
    expect(array_column($result['trace'], 'plugin'))->toBe([
        'agent_type_router',
        'chat_step',
        'chat_pipeline',
        'default_critic',
    ]);
});

test('run retries once on Critic rejection and exposes critic_feedback to the next pass', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $passes = 0;
    $sawFeedback = false;

    $registry->register('agent_type_router', staticRouter('content_pipeline'));
    $registry->register('content_step', fakeAgent('content_step', function (array $input, KernelContext $context) use (&$passes, &$sawFeedback) {
        $passes++;
        $sawFeedback = $sawFeedback || $context->has('critic_feedback');

        return ['response' => "draft #{$passes}"];
    }));
    $registry->register('content_pipeline', delegatingPipeline('content_pipeline', ['content_step'], $kernel));

    // Critic that rejects the first pass and approves the second.
    $criticPlugin = new class implements CriticPlugin
    {
        private int $calls = 0;

        public function getName(): string
        {
            return 'default_critic';
        }

        public function evaluate(array $output, KernelContext $context): CriticVerdict
        {
            $this->calls++;

            return new CriticVerdict(
                approved: $this->calls > 1,
                issues: $this->calls === 1 ? ['needs more detail'] : [],
                missing: [],
                missingTopics: [],
                improvementSuggestions: [],
                confidence: 'medium',
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
    $registry->register('default_critic', $criticPlugin);

    $result = $kernel->run('write a tweet');

    expect($passes)->toBe(2);
    expect($sawFeedback)->toBeTrue();
    expect($result['iterations'])->toBe(2);
    expect($result['critic']['approved'])->toBeTrue();
    expect($result['output']['response'])->toBe('draft #2');
});

test('run does not retry when critic rejects on the second pass either', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $passes = 0;

    $registry->register('agent_type_router', staticRouter('p'));
    $registry->register('s', fakeAgent('s', function () use (&$passes) {
        $passes++;

        return ['response' => 'noop'];
    }));
    $registry->register('p', delegatingPipeline('p', ['s'], $kernel));
    $registry->register('default_critic', staticCritic(approved: false));

    $result = $kernel->run('q');

    expect($passes)->toBe(2); // initial + one retry
    expect($result['iterations'])->toBe(2);
    expect($result['critic']['approved'])->toBeFalse();
});

test('run skips the critic entirely when withCritic is false', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $registry->register('agent_type_router', staticRouter('p'));
    $registry->register('s', fakeAgent('s', fn () => ['response' => 'x']));
    $registry->register('p', delegatingPipeline('p', ['s'], $kernel));
    // Intentionally no critic registered.

    $result = $kernel->run('q', withCritic: false);

    expect($result['critic'])->toBeNull();
    expect($result['output']['response'])->toBe('x');
});

test('stream yields pipeline frames then a critic phase frame', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $streamingPipeline = new class implements StreamablePipelinePlugin
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
            return [
                'result' => ['response' => 'sync fallback'],
                'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
            ];
        }

        public function stream(array $input, KernelContext $context): Generator
        {
            yield "data: chunk-1\n\n";
            yield "data: chunk-2\n\n";

            return ['query' => $input['query'] ?? '', 'response' => 'streamed'];
        }
    };

    $registry->register('agent_type_router', staticRouter('streaming_pipeline'));
    $registry->register('streaming_pipeline', $streamingPipeline);
    $registry->register('default_critic', staticCritic(approved: true));

    $context = new KernelContext('q');
    $phases = [];
    $context->set('yieldPhase', function (array $phase) use (&$phases): string {
        $phases[] = $phase;

        return 'data: '.json_encode(['type' => 'phase', ...$phase])."\n\n";
    });

    $generator = $kernel->stream('q', $context);
    $frames = iterator_to_array($generator, preserve_keys: false);

    expect($frames[0])->toBe("data: chunk-1\n\n");
    expect($frames[1])->toBe("data: chunk-2\n\n");
    expect(count($frames))->toBe(4); // 2 pipeline + 2 critic phase frames
    expect(array_column($phases, 'status'))->toBe(['running', 'complete']);
});

test('stream does not retry on critic rejection', function () {
    $registry = new PluginRegistry(new Container);
    $kernel = makeKernel($registry);

    $invocations = 0;
    $streamingPipeline = new class($invocations) implements StreamablePipelinePlugin
    {
        public function __construct(private int &$invocations) {}

        public function getName(): string
        {
            return 'p';
        }

        public function steps(): array
        {
            return [];
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => ['response' => 'x'],
                'meta' => ['plugin' => 'p', 'type' => 'pipeline'],
            ];
        }

        public function stream(array $input, KernelContext $context): Generator
        {
            $this->invocations++;
            yield "data: x\n\n";

            return ['query' => 'q', 'response' => 'x'];
        }
    };

    $registry->register('agent_type_router', staticRouter('p'));
    $registry->register('p', $streamingPipeline);
    $registry->register('default_critic', staticCritic(approved: false));

    iterator_to_array($kernel->stream('q'), preserve_keys: false);

    expect($invocations)->toBe(1); // streaming never retries
});

test('run delegates retry to a registered RetryStrategy when one matches the pipeline name', function () {
    $registry = new PluginRegistry(new Container);

    $registry->register('agent_type_router', staticRouter('research_pipeline'));

    $strategyCalls = 0;
    $customStrategy = new class($strategyCalls) implements RetryStrategy
    {
        public function __construct(private int &$calls) {}

        public function retry(Agent $pipeline, array $previous, array $criticFeedback): array
        {
            $this->calls++;

            return ['response' => 'merged retry result', 'query' => $previous['query'] ?? ''];
        }
    };

    $kernel = new AgentKernel(
        registry: $registry,
        defaultRetry: new ReplaceRetryStrategy,
        retryStrategies: ['research_pipeline' => $customStrategy],
    );

    $registry->register('researcher_step', fakeAgent('researcher_step', fn (array $input) => [
        'response' => "first pass: {$input['query']}",
    ]));
    $registry->register('research_pipeline', delegatingPipeline('research_pipeline', ['researcher_step'], $kernel));

    $criticPlugin = new class implements CriticPlugin
    {
        private int $calls = 0;

        public function getName(): string
        {
            return 'default_critic';
        }

        public function evaluate(array $output, KernelContext $context): CriticVerdict
        {
            $this->calls++;

            return new CriticVerdict(
                approved: $this->calls > 1,
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
    $registry->register('default_critic', $criticPlugin);

    $result = $kernel->run('what is X');

    expect($strategyCalls)->toBe(1);
    expect($result['output']['response'])->toBe('merged retry result');
});
