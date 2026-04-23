<?php

namespace App\Ai\Agents;

use App\Actions\Contracts\MultiAgentStreamAction;
use Laravel\Ai\Contracts\Tool;

/**
 * Shared base for chat-UI facades that front a multi-agent workflow
 * (Research, Router, and whatever comes next). Makes adding a new
 * workflow as minimal as extending this class + implementing
 * {@see basePrompt()} and overriding
 * {@see BaseAgent::streamingActionClass()}, mirroring the single-agent
 * bar of extending {@see BaseAgent} + implementing {@see toolsTag()}
 * and {@see basePrompt()}.
 *
 * Why a dedicated subclass instead of reusing BaseAgent directly:
 *
 * - Multi-agent facades are tool-free on purpose — the orchestration
 *   (tool calls, web lookups, analyses) happens in *sibling* agents
 *   that the streaming action invokes before/around the facade's
 *   visible stream. Overriding {@see toolsTag()} and {@see tools()}
 *   to return `[]` once, here, means every facade gets the right
 *   shape without having to repeat those two methods.
 * - The chat controller dispatches to the workflow-specific streaming
 *   action by class name via {@see BaseAgent::streamingActionClass()}.
 *   Every concrete facade overrides that method to point at its own
 *   {@see MultiAgentStreamAction} — forgetting to override would leave
 *   the facade on the single-agent default and silently bypass the
 *   workflow.
 */
abstract class MultiAgentFacade extends BaseAgent
{
    /**
     * Multi-agent facades never declare tools of their own — the
     * workflow's tool-using agent is a sibling invoked by the
     * streaming action, not this facade. Locking both hooks here
     * keeps subclasses from accidentally reintroducing tools.
     */
    protected function toolsTag(): string|array
    {
        return [];
    }

    /**
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return [];
    }
}
