<?php

namespace App\Ai\Agents\Content;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Second half of the content pipeline: takes the {@see GeneratorAgent}'s
 * draft and improves clarity, tone, and formatting without changing
 * meaning. Intentionally lower temperature than the Generator so the
 * polish pass doesn't invent or remove content.
 *
 * Mirrors the Researcher → Editor split on the research side: the first
 * agent produces raw material, the second shapes it into the final
 * user-facing answer.
 */
#[Temperature(0.3)]
#[MaxTokens(2048)]
#[Timeout(120)]
class RewriterAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You are a Rewriter Agent.

    You are given a draft produced by a Generator. Your job is to
    produce the final user-facing version — clearer, tighter,
    better-formatted — WITHOUT changing what it says.

    # Rules

    - Do NOT use tools. (You have none.)
    - Preserve all facts, claims, and meaning from the draft. You may
      drop filler and reorder for clarity, but do not add new
      information or remove substantive content.
    - Fix awkward phrasing, tighten prose, normalise tone.
    - Apply sensible formatting (headings, bullet lists, short
      paragraphs) when it makes the output easier to scan.
    - Return the final polished artefact only. No meta-commentary, no
      diff, no "here is the rewrite" preamble.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
