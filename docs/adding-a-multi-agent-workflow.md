# Adding a multi-agent chat workflow

A multi-agent workflow runs ≥ 2 LLM calls per user turn — typically a **worker** that produces structured intermediate state, then a **writer** (the chat-UI facade) that consumes that state and streams the visible answer. The chat UI sees normal SSE; phase chips appear between stages.

This doc walks through adding one called **Outline-and-Expand**: an `OutlinerAgent` produces a 3-5 bullet outline, then `ExpandedAnswerAgent` (the facade) expands it into a full markdown answer.

Before starting, read [`docs/adding-a-single-agent.md`](adding-a-single-agent.md) — the single-agent pattern is the floor; this doc shows what you add on top.

---

## Files at a glance

```
app/Ai/Agents/OutlineExpand/
├── OutlinerAgent.php                  # worker: plain Agent, no tools
└── ExpandedAnswerAgent.php            # facade: MultiAgentFacade subclass

app/Ai/Workflow/Kernel/Plugins/
├── Agents/OutlinerStepPlugin.php      # AgentPlugin wrapping OutlinerAgent
└── Pipelines/OutlineExpansionPipelinePlugin.php  # StreamablePipelinePlugin

app/Actions/OutlineExpand/
└── StreamExpandedAnswerResponse.php   # MultiAgentStreamAction (~25 lines)
```

Plus three one-line registrations: `KernelServiceProvider::PLUGINS`, `AgentType` case, `AgentType::pipelinePluginName()` arm.

---

## 1. The worker LLM agent

[`app/Ai/Agents/OutlineExpand/OutlinerAgent.php`](../app/Ai/Agents/OutlineExpand/OutlinerAgent.php)

Plain `Agent + Promptable` — **not** `BaseAgent`. Worker agents must not extend `BaseAgent` because that trait persists turns to the conversation; we only want the *facade* on the user's chat thread.

```php
<?php

namespace App\Ai\Agents\OutlineExpand;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Temperature(0.3)]
#[MaxTokens(512)]
class OutlinerAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are an Outliner. Given any user question, return a 3-5 item
        bullet outline of the topics the answer should cover. Return
        ONLY the bullet list, one item per line, prefixed with "- ".
        No prose, no preamble, no closing remarks.
        PROMPT;
    }
}
```

## 2. The facade

[`app/Ai/Agents/OutlineExpand/ExpandedAnswerAgent.php`](../app/Ai/Agents/OutlineExpand/ExpandedAnswerAgent.php)

Extends `MultiAgentFacade` (which locks `tools()` to `[]` and sets the right defaults). The `withOutline()` setter is how the pipeline plugin injects the worker's output into this agent's system prompt before it streams.

```php
<?php

namespace App\Ai\Agents\OutlineExpand;

use App\Actions\OutlineExpand\StreamExpandedAnswerResponse;
use App\Ai\Agents\MultiAgentFacade;
use Stringable;

class ExpandedAnswerAgent extends MultiAgentFacade
{
    protected ?string $outline = null;

    public static function streamingActionClass(): string
    {
        return StreamExpandedAnswerResponse::class;
    }

    public function withOutline(?string $outline): static
    {
        $this->outline = $outline;

        return $this;
    }

    protected function basePrompt(): Stringable|string
    {
        $base = <<<'PROMPT'
        You are a writer. Given a user question and a structured outline
        of topics to cover, produce a markdown answer with one short
        section per outline bullet. Use bullet points or short paragraphs
        as appropriate. Stay strictly within the outline — do not
        introduce topics it does not mention.
        PROMPT;

        if ($this->outline === null || $this->outline === '') {
            return $base;
        }

        return $base."\n\n# Outline to expand\n\n".$this->outline;
    }
}
```

## 3. The worker step plugin

[`app/Ai/Workflow/Kernel/Plugins/Agents/OutlinerStepPlugin.php`](../app/Ai/Workflow/Kernel/Plugins/Agents/OutlinerStepPlugin.php)

Wraps the LLM agent in the kernel's `{result, meta}` envelope. Soft-fails to an empty outline + warning so a broken worker never blocks the writer.

```php
<?php

namespace App\Ai\Workflow\Kernel\Plugins\Agents;

use App\Ai\Agents\OutlineExpand\OutlinerAgent;
use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OutlinerStepPlugin implements AgentPlugin
{
    public function getName(): string
    {
        return 'outliner_step';
    }

    public function execute(array $input, KernelContext $context): array
    {
        $query = (string) ($input['query'] ?? '');
        $warnings = (array) ($input['warnings'] ?? []);
        $outline = '';

        try {
            $outline = trim(OutlinerAgent::make()->prompt($query)->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('outliner.failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Outliner failed: '.$e->getMessage();
        }

        return [
            'result' => [...$input, 'outline' => $outline, 'warnings' => $warnings],
            'meta' => ['plugin' => $this->getName(), 'type' => 'agent'],
        ];
    }
}
```

## 4. The pipeline plugin

[`app/Ai/Workflow/Kernel/Plugins/Pipelines/OutlineExpansionPipelinePlugin.php`](../app/Ai/Workflow/Kernel/Plugins/Pipelines/OutlineExpansionPipelinePlugin.php)

Implements `StreamablePipelinePlugin`. **Sync `execute()`** dispatches the worker through the kernel — never instantiates it directly. **`stream()`** runs the worker sync (no live frames worth forwarding), emits a phase chip, configures the facade, and streams the visible answer.

```php
<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Agents\BaseAgent;
use App\Ai\Agents\OutlineExpand\ExpandedAnswerAgent;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use Closure;
use Generator;

final class OutlineExpansionPipelinePlugin implements StreamablePipelinePlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
    ) {}

    public function getName(): string
    {
        return 'outline_expansion_pipeline';
    }

    public function steps(): array
    {
        return ['outliner_step'];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $envelope = $this->kernel->dispatch('outliner_step', $input, $context);

        return [
            'result' => [...$input, ...$envelope['result']],
            'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
        ];
    }

    public function stream(array $input, KernelContext $context): Generator
    {
        $query = (string) ($input['query'] ?? '');
        $facade = $context->get('facade');
        $attachments = (array) $context->get('attachments', []);
        $model = $context->get('model');
        $yieldPhase = $context->get('yieldPhase');

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase(['key' => 'outliner', 'label' => 'Outlining', 'status' => 'running']);
        }

        $envelope = $this->kernel->dispatch('outliner_step', ['query' => $query], $context);
        $outline = (string) ($envelope['result']['outline'] ?? '');

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase(['key' => 'outliner', 'label' => 'Outlining', 'status' => 'complete']);
        }

        if ($facade instanceof ExpandedAnswerAgent) {
            $facade->withOutline($outline !== '' ? $outline : null);
        }

        $answer = '';

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase(['key' => 'answer', 'label' => 'Writing', 'status' => 'running']);
        }

        if ($facade instanceof BaseAgent) {
            foreach ($facade->stream($query, $attachments, model: $model) as $event) {
                $payload = $event->toArray();

                if (($payload['type'] ?? null) === 'text_delta') {
                    $answer .= (string) ($payload['delta'] ?? '');
                }

                yield "data: {$event}\n\n";

                if (connection_aborted()) {
                    break;
                }
            }
        }

        if ($yieldPhase instanceof Closure && ! connection_aborted()) {
            yield $yieldPhase(['key' => 'answer', 'label' => 'Writing', 'status' => 'complete']);
        }

        return [
            ...$input,
            'query' => $query,
            'outline' => $outline,
            'response' => trim($answer),
            'warnings' => $envelope['result']['warnings'] ?? [],
        ];
    }
}
```

## 5. The streaming action

[`app/Actions/OutlineExpand/StreamExpandedAnswerResponse.php`](../app/Actions/OutlineExpand/StreamExpandedAnswerResponse.php)

Boilerplate over the kernel — the chat controller dispatches here via `ExpandedAnswerAgent::streamingActionClass()`. The action only builds the `KernelContext` and forwards frames; the kernel does the actual work.

```php
<?php

namespace App\Actions\OutlineExpand;

use App\Actions\Chat\GenerateConversationTitle;
use App\Actions\Chat\LinkAssistantVariant;
use App\Actions\Concerns\EmitsAgentPhases;
use App\Actions\Concerns\StreamsMultiAgentWorkflow;
use App\Actions\Contracts\MultiAgentStreamAction;
use App\Ai\Agents\AgentType;
use App\Ai\Agents\BaseAgent;
use App\Ai\Storage\PendingTurnTracker;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Services\AttachmentService;
use App\Support\ModelPricing;
use Generator;
use Illuminate\Support\Carbon;

class StreamExpandedAnswerResponse implements MultiAgentStreamAction
{
    use EmitsAgentPhases;
    use StreamsMultiAgentWorkflow;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkAssistantVariant $linkVariant,
        private readonly ModelPricing $pricing,
        private readonly GenerateConversationTitle $generateTitle,
        private readonly PendingTurnTracker $pendingTurns,
        private readonly AgentKernel $kernel,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $phases
     */
    protected function workflowFrames(
        BaseAgent $agent,
        string $message,
        array $attachments,
        ?string $model,
        Carbon $pivot,
        array &$phases,
    ): Generator {
        $context = new KernelContext($message);
        $context->set('agent_type', AgentType::OutlineExpand);
        $context->set('facade', $agent);
        $context->set('attachments', $attachments);
        $context->set('model', $model);
        $context->set('yieldPhase', function (array $phase) use (&$phases): string {
            return $this->yieldPhase($phases, $phase);
        });

        $generator = $this->kernel->stream($message, $context, withCritic: false);

        foreach ($generator as $frame) {
            yield $frame;

            if (connection_aborted()) {
                return;
            }
        }
    }
}
```

---

## 6. Register everything

### Kernel plugins

[`app/Providers/KernelServiceProvider.php`](../app/Providers/KernelServiceProvider.php) — two lines added to the `PLUGINS` map:

```php
public const PLUGINS = [
    // ... existing entries ...
    'outliner_step' => OutlinerStepPlugin::class,
    'outline_expansion_pipeline' => OutlineExpansionPipelinePlugin::class,
];
```

### Agent type

[`app/Ai/Agents/AgentType.php`](../app/Ai/Agents/AgentType.php) — case + label + class + pipeline name:

```php
case OutlineExpand = 'outline-expand';

// in label():    self::OutlineExpand => 'Outline & Expand Mode',
// in agentClass(): self::OutlineExpand => ExpandedAnswerAgent::class,
// in pipelinePluginName(): self::OutlineExpand => 'outline_expansion_pipeline',
```

That's the whole registration surface. Notice **no controller edit, no `AgentTypeRouter` edit, no `AgentKernel` edit** — all routing flows through the enum + the registry.

---

## 7. Verify

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

In the chat UI, pick **Outline & Expand Mode** and ask `"What is the difference between TCP and UDP?"`. You should see:
1. Phase chip: **Outlining** (running → complete)
2. Phase chip: **Writing** (running → complete) with the visible answer streaming token-by-token underneath

The persisted assistant row carries the final markdown; the outline lives only in the `KernelContext::$trace` (run `php artisan pail` to see kernel log lines if you want to inspect it live).

---

## What you got for free

- **Phase chips** in the chat UI by yielding through `yieldPhase` — the trait persists them onto the assistant row's `meta.phases` so they survive a refresh.
- **Conversation persistence** for the visible answer via the facade's `RemembersConversations`. The worker's output never touches the conversation table.
- **The kernel trace** (`KernelContext::$trace`) records every dispatch with timing — the worker step shows up alongside the pipeline.
- **All the SSE postamble** (attachments, pending-turn tracking, usage frame, conversation frame, autotitle, error framing, `[DONE]`) inherited from `StreamsMultiAgentWorkflow` exactly like single-agent flows.

---

## When you'd need more

The pattern above is the floor. Step up when you need:

- **A `Critic` quality gate + retry on rejection.** Register a `CriticPlugin`, call `$kernel->stream(withCritic: true)` (or `$kernel->run()` for sync flows). Rejections in sync mode trigger one retry pass via the registered `RetryStrategy`.
- **A worker that uses tools with live tool-call frames forwarded to the UI.** Implement the worker as a `Agent + HasTools`, then drive its `stream()` directly inside the pipeline plugin's `stream()` — see [`ResearcherStreamer`](../app/Ai/Workflow/Support/ResearcherStreamer.php) and [`ResearchPipelinePlugin`](../app/Ai/Workflow/Kernel/Plugins/Pipelines/ResearchPipelinePlugin.php) as the reference shape.
- **Classification → specialist dispatch** (one of N pipelines based on classifier verdict). [`RouterPipelinePlugin`](../app/Ai/Workflow/Kernel/Plugins/Pipelines/RouterPipelinePlugin.php) is the reference.
- **A JSON / sync entry point** alongside the streaming chat UI. Add an action that calls `$kernel->run()` (instead of `stream()`) and reshapes the kernel envelope into your endpoint's payload — [`RunResearchAssistant`](../app/Actions/Research/RunResearchAssistant.php) is the reference.

Cross-reference: [`docs/multi-agent-workflows.md`](multi-agent-workflows.md) covers all of the above in depth, plus the pitfalls list (workers as `BaseAgent`, polluting the user message, recursing into your own tools, etc.).
