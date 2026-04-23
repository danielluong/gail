<?php

namespace App\Ai\Agents\Research;

use App\Ai\Tools\Research\ExtractFactsTool;
use App\Ai\Tools\Research\SummarizeTextTool;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Bare-bones one-shot LLM used by {@see SummarizeTextTool}
 * and {@see ExtractFactsTool}. Mirrors the TitlerAgent
 * pattern: plain {@see Agent} (not BaseAgent) so it has no tools, no
 * conversation persistence, and no context providers — we never want a
 * summariser recursing back into web tools or polluting chat history.
 *
 * Instructions are supplied per-call via the prompt body; the agent's own
 * instructions() stays minimal and focused on output hygiene.
 */
#[Temperature(0.2)]
#[MaxTokens(1024)]
#[Timeout(30)]
class LlmCallerAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a precise text utility. Follow the task instructions in the
        user message exactly. Do not add preambles, disclaimers, or
        commentary. If asked for JSON, return only JSON with no markdown
        fencing. If asked for a summary, return only the summary.
        PROMPT;
    }
}
