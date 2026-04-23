<?php

namespace App\Actions\Research;

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

/**
 * SSE streaming variant of the multi-agent research pipeline for the
 * chat UI. After the Kernel migration this is a pure composition: build
 * a {@see KernelContext} stamped with `agent_type = Research` plus the
 * facade + per-request streaming inputs, hand it to
 * {@see AgentKernel::stream()}, forward frames verbatim, and patch the
 * Researcher's tool activity onto the persisted assistant row from the
 * final return dict.
 *
 * The retry loop from the JSON endpoint is intentionally disabled here
 * — keeping one persisted assistant row per user turn matches the chat
 * UX; users who want another pass click regenerate. The Kernel honours
 * this by skipping the auto-retry on streaming.
 */
class StreamResearchResponse implements MultiAgentStreamAction
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
        $context->setAgentType(AgentType::Research);
        $context->setFacade($agent);
        $context->setAttachments($attachments);
        $context->setModelOverride($model);
        $context->setYieldPhase(function (array $phase) use (&$phases): string {
            return $this->yieldPhase($phases, $phase);
        });

        $generator = $this->kernel->stream($message, $context);

        foreach ($generator as $frame) {
            yield $frame;

            if (connection_aborted()) {
                return;
            }
        }

        $output = $generator->getReturn()['output'];

        /*
         * Patch the Researcher's tool activity onto the assistant row
         * laravel/ai just persisted via the facade's stream. Without
         * this, tool badges vanish on refresh AND OpenAI's next-turn
         * history replay 400s on orphan tool_calls. Skip when we have
         * no conversation id (unpersisted turn) or no tool activity.
         */
        if ($conversationId = $agent->currentConversation()) {
            $this->persistSiblingToolActivity(
                $conversationId,
                $pivot,
                $output['tool_calls'] ?? [],
                $output['tool_results'] ?? [],
            );
        }
    }
}
