# Multi-agent workflows

Every chat-UI and JSON-endpoint flow in this app routes through one runtime — the **Agent Kernel** — which dispatches every executable unit (agent, pipeline, router, critic) as a named **plugin**:

```
Router → Pipeline (≥1 Agent) → Critic? (one retry on sync rejection)
```

Single-agent chats, the Research Assistant, and the Classifier → Router → Specialist flow are all expressed as Pipeline plugins. A one-step pipeline is just as valid as a five-step one — the kernel can't tell them apart.

This document walks through the building blocks and the shape of a new workflow.

---

## 1. The Kernel runtime

The whole orchestration model lives in [`app/Ai/Workflow/Kernel/`](../app/Ai/Workflow/Kernel/):

| Piece | What it does |
|---|---|
| [`AgentKernel`](../app/Ai/Workflow/Kernel/AgentKernel.php) | The only orchestrator. Two entry points share one execution model: `run($input, $context)` (sync, with one-shot retry on Critic rejection) and `stream($input, $context)` (SSE, no auto-retry — UX policy). |
| [`KernelContext`](../app/Ai/Workflow/Kernel/KernelContext.php) | Mutable shared state passed to every plugin call. Holds `originalInput`, `selectedPipeline`, `retryCount`, and an append-only `trace`. Seven well-known metadata keys — `agent_type`, `facade`, `yieldPhase`, `attachments`, `model`, `classification`, `critic_feedback` — are exposed through typed accessors (`agentType()`, `facade()`, `yieldPhase()`, `attachments()`, `modelOverride()`, `classification()`, `criticFeedback()`) plus matching setters. Plugin-specific state still uses the untyped `set()`/`get()`/`has()` bag. |
| [`PluginRegistry`](../app/Ai/Workflow/Kernel/PluginRegistry.php) | Name → plugin lookup. Lazy by default — bindings resolve through the Laravel container on first dispatch. |
| [`KernelServiceProvider`](../app/Providers/KernelServiceProvider.php) | The single point where plugins are registered. Adding a plugin is a one-line `PLUGINS` map edit. |

### Plugin contracts

All plugins implement [`Plugin`](../app/Ai/Workflow/Kernel/Contracts/Plugin.php) (`getName()` + `execute(array, KernelContext): {result, meta}`). Four sub-interfaces split the role:

| Interface | Role |
|---|---|
| [`AgentPlugin`](../app/Ai/Workflow/Kernel/Contracts/AgentPlugin.php) | Atomic unit — one LLM call or one deterministic transformation. |
| [`PipelinePlugin`](../app/Ai/Workflow/Kernel/Contracts/PipelinePlugin.php) | Ordered composite. `steps()` returns a list of plugin names; `execute()` calls `$kernel->dispatch()` for each. **Pipelines never instantiate steps directly** — the kernel resolves them through the registry. |
| [`StreamablePipelinePlugin`](../app/Ai/Workflow/Kernel/Contracts/StreamablePipelinePlugin.php) | Adds `stream()` for the chat-UI streaming path. Yields already-framed SSE strings; returns the final context dict via `Generator::getReturn()`. |
| [`RouterPlugin`](../app/Ai/Workflow/Kernel/Contracts/RouterPlugin.php) | `select()` returns a pipeline plugin name — never executes anything. |
| [`CriticPlugin`](../app/Ai/Workflow/Kernel/Contracts/CriticPlugin.php) | `evaluate()` returns a structured verdict — never rewrites output. |

### Underlying primitives (still in use, wrapped by plugins)

| Contract | Purpose |
|---|---|
| [`App\Ai\Workflow\Contracts\Agent`](../app/Ai/Workflow/Contracts/Agent.php) — `run(array): array` | Workflow-layer adapter shape. The `Step` classes (`ResearcherStep`, `EditorStep`, …) implement this; plugin adapters wrap them. |
| [`App\Ai\Workflow\Contracts\Router`](../app/Ai/Workflow/Contracts/Router.php) — `route(array): string` | Pure-PHP confidence-floor + path map. [`UniversalRouter`](../app/Ai/Workflow/Routing/UniversalRouter.php) is the lone implementation; the kernel router calls it from inside `select()`. |
| [`App\Ai\Workflow\Contracts\Critic`](../app/Ai/Workflow/Contracts/Critic.php) — `evaluate(array): CriticVerdict` | LLM-based verdict returning a typed [`CriticVerdict`](../app/Ai/Workflow/Dto/CriticVerdict.php) DTO. [`CriticAgentEvaluator`](../app/Ai/Workflow/Critics/CriticAgentEvaluator.php) is the lone implementation; wrapped by [`CriticEvaluatorPlugin`](../app/Ai/Workflow/Kernel/Plugins/Critics/CriticEvaluatorPlugin.php). The plugin's `execute()` envelope serializes via `$verdict->toArray()` so downstream JSON callers and step-level `critic_feedback` reads still work. |
| [`App\Ai\Workflow\Contracts\RetryStrategy`](../app/Ai/Workflow/Contracts/RetryStrategy.php) — `retry(Agent, array, array): array` | How a rejected pipeline's retry pass is produced. Default [`ReplaceRetryStrategy`](../app/Ai/Workflow/Retry/ReplaceRetryStrategy.php); research has [`MergeResearchRetryStrategy`](../app/Ai/Workflow/Retry/MergeResearchRetryStrategy.php). The kernel reads a per-pipeline-name strategy map. |

The only LLM-facing abstraction remains `BaseAgent` (laravel/ai-level, with conversation persistence). Workflow-layer **Agents never call each other** — composition happens via Pipelines, and Pipelines delegate every step through the kernel.

---

## 2. Dispatch

`ChatController::stream()` is unchanged — every `BaseAgent` subclass still declares its `streamingActionClass()`:

```php
return app($agent::streamingActionClass())->execute(agent: $agent, message: …);
```

Each `Stream*Response` action body is now a tiny composition (~25 lines): build a `KernelContext`, stamp the `agent_type` + per-request streaming inputs (facade, attachments, model, yieldPhase), and `yield from $kernel->stream(…)`. The shared SSE bootstrap + postamble (attachments, pending turn, usage frame, auto-title, `[DONE]`, error framing) still lives in [`StreamsMultiAgentWorkflow`](../app/Actions/Concerns/StreamsMultiAgentWorkflow.php).

---

## 3. The flows in-tree

### Chat (single-agent — Default / Limerick / MySQL)
```
ChatController::stream
  → StreamChatResponse (default for plain BaseAgents)
  → KernelContext{agent_type=Default|Limerick|MySQL, facade=$agent, …}
  → AgentKernel::stream
      → AgentTypeRouter.select          → "single_agent_pipeline"
      → SingleAgentPipelinePlugin.stream → $facade->stream()  (text_delta + tool_call frames live)
      (no critic; UI shows the agent's own output)
```

### Router (Classifier → specialist)
```
ChatController::stream
  → StreamRouterResponse
  → KernelContext{agent_type=Router, facade=RouterAgent, yieldPhase, …}
  → AgentKernel::stream
      → AgentTypeRouter.select          → "router_pipeline"
      → RouterPipelinePlugin.stream
          → phase(classifier, running) → dispatch('classifier_step') → phase(classifier, complete with category/confidence)
          → UniversalRouter.routeCategory  (deterministic PHP, confidence floor)
          → facade->withCategory/withConfidence/withClassifierWarning
          → phase(answer, running) → facade->stream() → phase(answer, complete)
      (no critic — regenerate covers retry)
```

### Research (multi-step + Critic)
```
ChatController::stream
  → StreamResearchResponse
  → KernelContext{agent_type=Research, facade=ResearchAgent, yieldPhase, …}
  → AgentKernel::stream(withCritic: true)
      → AgentTypeRouter.select           → "research_pipeline"
      → ResearchPipelinePlugin.stream
          → phase(researcher, running) → ResearcherStreamer (tool_call frames forwarded live) → phase(researcher, complete)
          → phase(editor, running) → ResearchAgent facade streams (research JSON injected via withResearch) → phase(editor, complete)
      → phase(critic, running) → default_critic.evaluate → phase(critic, complete with verdict)
      (streaming: NO retry — users regenerate; sync flow does merge-retry)
```

The `ResearchPipelinePlugin` declares `steps() = ['researcher_step', 'editor_step']`; sync `execute()` runs them through the kernel uniformly, while `stream()` is bespoke because (a) the Researcher's tool-call frames need live forwarding via `ResearcherStreamer` and (b) the Editor output is produced by the chat-UI facade so its `RemembersConversations` trait persists the assistant row.

---

## 4. Sync / JSON path

The JSON endpoints route through the same kernel. They're now thin output-reshape adapters:

- [`RunUniversalAssistant`](../app/Actions/UniversalAssistant/RunUniversalAssistant.php) — handles the empty-input short-circuit, calls `$kernel->run()` (no `agent_type` hint, so the router runs the classifier + UniversalRouter dispatch), and reshapes the kernel envelope into the endpoint's historical `{category, confidence, selected_path, response, research, critic, iterations, warnings}` payload. The classifier verdict is stashed by the router on `KernelContext::$metadata['classification']` so the adapter can read it back.
- [`RunResearchAssistant`](../app/Actions/Research/RunResearchAssistant.php) — stamps `agent_type = Research` so the router skips classification and dispatches the research pipeline directly. Reshape into `{answer, research, critic, iterations, warnings}`. The merge-retry semantics come from the `MergeResearchRetryStrategy` registered for `research_pipeline` in `KernelServiceProvider`.

---

## 5. Adding a new workflow

Pick the shape that matches what you're building. Every shape ends with **one line in `KernelServiceProvider::PLUGINS`** and **one `AgentType` case**.

### A single-agent workflow (Chat-like)
1. Extend `BaseAgent`. Implement `basePrompt()` and `toolsTag()`.
2. Add an `AgentType` enum case mapping to the agent class.

That's it — `StreamChatResponse` is the default streaming action, and `AgentTypeRouter` already routes any non-multi-agent type to `single_agent_pipeline` (which uses the facade from context). No new plugin, no new action, no controller change.

### A Classifier-routed workflow
Use `AgentTypeRouter`'s Mode A path with a streaming pipeline plugin that does the classification internally — see `RouterPipelinePlugin` as the reference shape.
1. Build any new step plugins under `app/Ai/Workflow/Kernel/Plugins/Agents/` (each wraps a `Step` adapter under `app/Ai/Workflow/Steps/`).
2. Build a `StreamablePipelinePlugin` under `app/Ai/Workflow/Kernel/Plugins/Pipelines/`. Its `stream()` reads `facade` + `yieldPhase` from `KernelContext` and dispatches each step via `$kernel->dispatch()`.
3. Register both in `KernelServiceProvider::PLUGINS`.
4. Add an `AgentType` case + extend `MultiAgentFacade` for the chat-UI front. Override `streamingActionClass()` to a new action that mirrors `StreamRouterResponse`.

### A multi-agent pipeline with Critic (Research-like)
1. Build worker step adapters under `app/Ai/Workflow/Steps/` (plain `Agent + HasTools` for tool users — never `BaseAgent` for workers, or their tool calls accidentally persist as chat history).
2. Wrap each in an `AgentPlugin` adapter.
3. Build a `StreamablePipelinePlugin` whose `steps()` returns the step plugin names and whose `execute()` iterates them via `$kernel->dispatch()`. Override `stream()` only if you need bespoke per-step streaming behaviour.
4. If you want non-default Critic retry semantics, ship a `RetryStrategy` and add it to `KernelServiceProvider`'s `retryStrategies` map keyed by pipeline name.
5. Extend `MultiAgentFacade` + override `streamingActionClass()` to a new action that mirrors `StreamResearchResponse`: builds the context, calls `$kernel->stream(withCritic: true)`, patches sibling tool activity onto the assistant row via `persistSiblingToolActivity()`.
6. Register the plugin(s) in `KernelServiceProvider::PLUGINS` and add the `AgentType` case.

The shared scaffolding guarantees the SSE contract (`text_delta`, `tool_call`, `tool_result`, `phase`, `message_usage`, `conversation`, `warning`, `[DONE]`) stays identical across every workflow — the chat UI can't tell them apart.

---

## 6. Shared helpers

Reuse these rather than rolling your own:

- [`AgentJsonDecoder`](../app/Ai/Support/AgentJsonDecoder.php) — lenient JSON recovery (strips ` ```json ` fences, falls back to object-span).
- [`JsonAgentCall::tryDecode`](../app/Ai/Support/JsonAgentCall.php) — soft-fail wrapper around any JSON-emitting agent; returns `[parsed, warning]`.
- [`EmitsAgentPhases::yieldPhase`](../app/Actions/Concerns/EmitsAgentPhases.php) — emit a `phase` SSE frame + merge into the accumulator by key. Bind to the action's phases array via a closure stashed through `KernelContext::setYieldPhase(…)` and read back via `$context->yieldPhase()`.
- [`EmitsAgentPhases::persistSiblingToolActivity`](../app/Actions/Concerns/EmitsAgentPhases.php) — patch a tool-using sibling worker's activity onto the facade's persisted row; pair-filters so OpenAI history replay doesn't 400.
- [`CriticAgentEvaluator`](../app/Ai/Workflow/Critics/CriticAgentEvaluator.php) — the one `Critic` implementation, wrapping `CriticAgent` with soft-fail defaults.
- [`ResearcherStreamer`](../app/Ai/Workflow/Support/ResearcherStreamer.php) — reference implementation of streaming a tool-using worker agent while forwarding its tool frames live.

---

## 7. Pitfalls

**Extending `MultiAgentFacade` but forgetting to override `streamingActionClass()`.** The facade falls back to `StreamChatResponse` and your workflow never runs. Override on every concrete subclass.

**Pipelines that instantiate steps directly.** Pipelines must call `$kernel->dispatch($stepName, …)` for every step. Bypassing the kernel breaks the trace, retry feedback threading, and the "Kernel is the only orchestrator" rule.

**Making a worker agent a `BaseAgent`.** Its tool calls accidentally persist as chat history and pollute the next turn's context. Worker/writer/reviewer are plain `Agent + HasTools` implementations for a reason.

**Polluting the DB user message with augmented prompts.** Don't inject worker findings into the `$message` argument of the facade's `->stream()`; laravel/ai persists that verbatim as the user row. Inject via `basePrompt()` (system prompt) or a workflow-specific fluent setter (e.g. `ResearchAgent::withResearch()`).

**Recursing into your own tools from the writer.** If the writer calls an LLM helper (e.g. for JSON extraction on internal state), make it a tool-free one-shot. A writer that can invoke `WebSearchTool` breaks the orchestration contract.

**Streaming retry.** The Critic retry loop only runs in `AgentKernel::run()` (sync). Streaming emits the verdict as a phase frame but never auto-retries — running a second writer pass would persist two assistant rows for one user turn. Users who want another pass click regenerate. Documented as a deliberate divergence on `AgentKernel`.

---

## 8. Checklist for a new workflow

- [ ] Domain-specific tools under `app/Ai/Tools/{Domain}/`, registered under an `ai.tools.{domain}` tag.
- [ ] Worker agent(s): plain `Agent + HasTools`, strict JSON output, bounded `MaxSteps`.
- [ ] Writer agent (optional): plain `Agent`, no tools, prompt exposed as `public const PROMPT` if the chat-UI facade reuses it.
- [ ] Workflow-layer Step adapters (`app/Ai/Workflow/Steps/`) — one per LLM agent involved.
- [ ] Step plugin adapters (`app/Ai/Workflow/Kernel/Plugins/Agents/`) — one per Step, each wrapping the Step in the `{result, meta}` envelope.
- [ ] Pipeline plugin (`app/Ai/Workflow/Kernel/Plugins/Pipelines/`) — `StreamablePipelinePlugin` if you want chat-UI streaming. `steps()` returns plugin names; `execute()` dispatches via the kernel.
- [ ] Chat-UI facade: `MultiAgentFacade` subclass + `streamingActionClass()` override.
- [ ] Streaming action: `implements MultiAgentStreamAction`, `use EmitsAgentPhases, StreamsMultiAgentWorkflow;`, builds `KernelContext` + delegates to `$kernel->stream()`.
- [ ] `RetryStrategy` if the default replace-retry isn't right (rare). Add to `KernelServiceProvider`'s `retryStrategies` map.
- [ ] One line in `KernelServiceProvider::PLUGINS` per new plugin.
- [ ] `AgentType` enum case (with `agentClass()` mapping for `AgentType::fromAgentClass()` reverse lookup).
- [ ] Pest coverage: Steps (factory-based), pipeline plugin `execute()` + `stream()`, the streaming action's tool-activity patching (reflection-over-private-method).
- [ ] Run `vendor/bin/pint --dirty`, `vendor/bin/phpstan`, `php artisan test --compact`, `npm test`, `npm run types:check`.
