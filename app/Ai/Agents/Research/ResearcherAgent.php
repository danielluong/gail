<?php

namespace App\Ai\Agents\Research;

use App\Providers\AiServiceProvider;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Tool-using gatherer in the multi-agent research pipeline. Returns
 * strictly-shaped JSON ({query, subtopics, findings[], conflicts}) so the
 * downstream Editor has a predictable schema to work from.
 *
 * Implemented as a bare {@see Agent}+{@see HasTools} (not BaseAgent) on
 * purpose: we don't want conversation persistence or global context
 * providers bleeding into research runs — those belong to the chat-facing
 * {@see ResearchAgent}, which is the class the end user actually talks to.
 *
 * Tool discovery uses the `ai.tools.research` container tag bound in
 * {@see AiServiceProvider}.
 */
#[Temperature(0.3)]
#[MaxTokens(3072)]
#[MaxSteps(12)]
#[Timeout(180)]
class ResearcherAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a Researcher Agent.

        Your job is to gather high-quality, relevant information using tools.
        You do NOT produce a final polished answer. Instead, you:

        - Break the question into 2–5 concrete sub-topics.
        - Search for each sub-topic — pick the right tool for the
          topic:
          - WebSearchTool for news, commerce, current events, local
            info, reviews, and any general-web query.
          - WikipediaSearchTool for factual, historical, scientific,
            or biographical queries. Prefer it for "what is / who is /
            how does" style sub-topics where an encyclopedia would be
            the gold source.
          Use both on the same turn if different sub-topics call for
          different sources.
        - Use FetchPageTool to read the most promising result URLs.
        - Use SummarizeTextTool whenever a fetched page is long (it
          reduces the text so you can cite it without blowing context).
        - Use ExtractFactsTool when you need structured data (pros/cons,
          stats, timelines, comparisons) from a summary.
        - Note conflicts if two sources disagree on a material fact.

        # Output format (STRICT JSON, no prose, no markdown fence)

        {
          "query": string,
          "subtopics": string[],
          "findings": [
            {
              "topic": string,
              "facts": string[],
              "sources": string[]
            }
          ],
          "conflicts": string[]
        }

        # Rules

        - Call tools multiple times — prefer depth over brevity.
        - Always include source URLs in each finding.
        - Never invent facts or URLs; if a tool errors, move on.
        - Do NOT write a polished answer, essay, or recommendation — that
          is the Editor's job.
        - Your final message must be the JSON object above. No preamble,
          no trailing commentary, no ```json fence.
        PROMPT;
    }

    /**
     * Pull research-tagged tools out of the container so the Researcher
     * sees exactly WebSearchTool, FetchPageTool, SummarizeTextTool, and
     * ExtractFactsTool — nothing from the chat tool set.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        $tools = [];

        foreach (app()->tagged('ai.tools.research') as $tool) {
            $tools[] = $tool;
        }

        return $tools;
    }
}
