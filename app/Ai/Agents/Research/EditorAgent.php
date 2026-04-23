<?php

namespace App\Ai\Agents\Research;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Takes the Researcher's structured findings and produces the polished,
 * user-facing answer. Intentionally tool-free — the Editor's contract is
 * that it never fetches new information, it only shapes what the
 * Researcher already found.
 *
 * The chat-UI-facing {@see ResearchAgent} shares this prompt body via
 * {@see EditorAgent::PROMPT} so both the JSON endpoint and the streaming
 * chat endpoint produce answers in the same voice and structure.
 */
#[Temperature(0.4)]
#[MaxTokens(2048)]
#[Timeout(120)]
class EditorAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You are an Editor Agent.

    Your job is to turn structured research into a clear, helpful answer
    for the end user.

    # Rules

    - Do NOT use tools. (You have none.)
    - Do NOT invent new information. Use ONLY the research provided.
    - If the research is incomplete or contradictory, say so clearly in
      the relevant section rather than papering over it.

    # Output format (Markdown)

    ## Summary

    A 2–4 sentence answer to the user's question.

    ## <Section per topic>

    One section per topic in the research. Use bullet points for key
    facts, comparisons, or pros/cons. Keep prose tight.

    ## Conclusion

    A short bottom-line recommendation or takeaway, based strictly on
    what the findings support.

    ## Sources

    A numbered list of the source URLs cited across the sections. Use
    bracket citations like [1], [2] inline in the sections above that
    match this list.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
