# Adding a single chat agent (no tools)

The simplest agent in the system is a `BaseAgent` subclass with no tools — pure system prompt + user input. It plugs into the chat-UI dropdown, persists its conversation, and inherits all the streaming + autotitle plumbing for free.

This doc walks through adding one called **`Eli5Agent`** ("Explain Like I'm 5"). Two files change. No kernel edit, no controller edit.

---

## 1. Create the agent class

[`app/Ai/Agents/Eli5Agent.php`](../app/Ai/Agents/Eli5Agent.php)

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Stringable;

#[Temperature(0.6)]
#[MaxTokens(1024)]
class Eli5Agent extends BaseAgent
{
    /**
     * No agent-specific tools — the inherited `ai.tools.core` tag
     * (notes + project-document search) is the only tool surface this
     * agent gets. Returning an empty list cleanly opts out of all
     * domain-specific tools.
     */
    protected function toolsTag(): string|array
    {
        return [];
    }

    protected function basePrompt(): Stringable|string
    {
        return <<<'PROMPT'
        You explain things to a curious five-year-old. For any question
        the user asks, produce one short paragraph (≤ 4 sentences) that:

        - uses everyday words a small child would know,
        - reaches for a concrete analogy or a tiny story when helpful,
        - never uses jargon, acronyms, math, or footnotes.

        If the question is genuinely impossible to simplify, say so in
        one sentence rather than dumbing it down dishonestly.
        PROMPT;
    }
}
```

Things to note:
- **No constructor.** Tools, context providers, and conversation persistence are wired through traits + container tags on `BaseAgent`.
- **`toolsTag()` returns `[]`** to opt out of every domain-specific tag. The agent still gets the `ai.tools.core` tag (notes + document search) automatically — that mirrors the read side of the context pipeline. If you want a truly pure agent with zero tools, you'd need to override `tools()` directly; for almost every chat-style use case the core tools are what you want.
- **`#[Temperature]` / `#[MaxTokens]`** are PHP attributes from `laravel/ai`. Omit them to use the defaults declared on `BaseAgent` (0.7 / 4096).
- **No `streamingActionClass()` override.** Plain `BaseAgent` subclasses inherit `StreamChatResponse` — the default chat-UI streaming action. That's all you need for a single-agent flow.

---

## 2. Register it in the `AgentType` enum

[`app/Ai/Agents/AgentType.php`](../app/Ai/Agents/AgentType.php)

Add a case, a label, and the class mapping:

```php
enum AgentType: string
{
    case Default = 'default';
    case Research = 'research';
    case Router = 'router';
    case Limerick = 'limerick';
    case MySQLDatabase = 'mysql-database';
    case Eli5 = 'eli5';                     // <-- new

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Default Mode',
            self::Research => 'Research Mode',
            self::Router => 'Router Mode',
            self::Limerick => 'Limerick Mode',
            self::MySQLDatabase => 'MySQL Mode',
            self::Eli5 => 'ELI5 Mode',      // <-- new
        };
    }

    public function agentClass(): string
    {
        return match ($this) {
            self::Default => ChatAgent::class,
            self::Research => ResearchAgent::class,
            self::Router => RouterAgent::class,
            self::Limerick => LimerickAgent::class,
            self::MySQLDatabase => MySQLDatabaseAgent::class,
            self::Eli5 => Eli5Agent::class,  // <-- new
        };
    }
}
```

That's the whole registration. The chat-UI dropdown reads `AgentType::options()`, so the new case appears automatically; `AgentTypeRouter` defaults any non-multi-agent type to `single_agent_pipeline` (which uses the per-request facade from context); `KernelServiceProvider` doesn't need any plugin entry because there's no new pipeline or step.

---

## 3. Verify

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Then in the chat UI: open the dropdown, pick **ELI5 Mode**, ask a question. You should see token-streamed text in the same SSE shape every other agent uses (`text_delta`, `message_usage`, `conversation`, `[DONE]`). Refresh the page — the conversation persists.

For a quick sanity check without the UI:

```bash
php artisan tinker --execute '
    $agent = app(App\Ai\Agents\Eli5Agent::class);
    echo $agent->prompt("Why is the sky blue?")->text;
'
```

---

## What you got for free

- **Conversation persistence** via `RemembersConversations` on `BaseAgent` — every prompt is associated with the chat thread the user is in.
- **Context pipeline** — every registered `ai.context_providers` provider (global notes, project notes, etc.) automatically prepends to the system prompt. Override `basePrompt()` to add your *base* prompt; the pipeline composes the rest.
- **Auto-title generation** on the first assistant turn (via `GenerateConversationTitle`).
- **Attachment + token-usage frames** through the shared `StreamsMultiAgentWorkflow` trait.
- **The Kernel trace** — every dispatch records `{plugin, type, duration_ms}` on `KernelContext::$trace` for observability.

---

## When you'd need more

The two-file pattern above only covers chat-style single-agent flows. Step up to a multi-agent pipeline when you need:

- Multiple LLM calls per turn (e.g. classify-then-answer, draft-then-polish, research-then-write).
- A `Critic` quality gate + retry on rejection.
- Live tool-call frames forwarded from a worker agent while a separate writer produces the visible answer.

That route requires a `StreamablePipelinePlugin` plus per-step `AgentPlugin` adapters; see [`docs/multi-agent-workflows.md`](multi-agent-workflows.md) for the full pattern.
