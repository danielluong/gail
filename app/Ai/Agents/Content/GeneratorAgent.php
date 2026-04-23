<?php

namespace App\Ai\Agents\Content;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * First half of the content pipeline: produces a raw draft for a task
 * request (write / summarize / transform / generate). The Generator is
 * purposefully unpolished — its job is to cover the ground; the
 * {@see RewriterAgent} downstream does the tightening, tone, and
 * formatting pass.
 *
 * Tool-free by design so generation stays fast and deterministic. For
 * tasks that need tool-assisted research, the classifier should route
 * to the research pipeline instead.
 */
#[Temperature(0.5)]
#[MaxTokens(2048)]
#[Timeout(120)]
class GeneratorAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You are a Generator Agent.

    Your job is to produce a first-pass draft that fulfils the user's
    task — writing, summarising, transforming, or generating content.

    # Rules

    - Do NOT use tools. (You have none.)
    - Cover the ground. Prefer completeness over polish at this stage.
    - Do not editorialise about the task or narrate what you are doing;
      just produce the content.
    - If the request is ambiguous, pick a reasonable interpretation and
      state it in one short line at the top, then proceed.

    The Rewriter downstream will tighten prose, adjust tone, and apply
    final formatting. Your output does not need to be the finished
    artefact, but it should be complete enough for the Rewriter to polish
    without re-generating material.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
