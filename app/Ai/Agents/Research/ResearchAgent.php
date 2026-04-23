<?php

namespace App\Ai\Agents\Research;

use App\Actions\Research\StreamResearchResponse;
use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\MultiAgentFacade;
use Stringable;

/**
 * Chat-UI-facing agent for the Smart Research Assistant. Extends
 * {@see MultiAgentFacade} so conversation persistence, project
 * binding, message history, and the tool-free facade shape all come
 * for free — same baseline as {@see ChatAgent} minus the tools.
 *
 * At runtime, {@see StreamResearchResponse} runs the Researcher
 * synchronously *before* calling ->stream() on this agent, stashes
 * the research JSON via {@see withResearch()}, and the injected
 * payload appears in this agent's system prompt via {@see basePrompt()}.
 * The stream itself is just the Editor writing the final answer, which
 * is what the end user sees in the chat bubble.
 */
class ResearchAgent extends MultiAgentFacade
{
    protected ?string $research = null;

    public static function streamingActionClass(): string
    {
        return StreamResearchResponse::class;
    }

    /**
     * Attach the Researcher's JSON output so it is injected into this
     * agent's system prompt on the next ->stream()/->prompt() call.
     * Returning static keeps the fluent chain used by callers.
     */
    public function withResearch(?string $researchJson): static
    {
        $this->research = $researchJson;

        return $this;
    }

    protected function basePrompt(): Stringable|string
    {
        $prompt = EditorAgent::PROMPT;

        if ($this->research === null || $this->research === '') {
            return $prompt;
        }

        return $prompt."\n\n# Research findings\n\n".$this->research
            ."\n\n# Reminder\n\nUse ONLY the research above. If a topic is missing from the findings, say so in the relevant section rather than inventing content.";
    }
}
