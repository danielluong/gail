<?php

namespace App\Actions\Router;

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
 * SSE streaming variant of the router workflow for the chat UI. After
 * the Kernel migration this is a pure composition: stamp `agent_type =
 * Router` on the {@see KernelContext}, hand the per-request facade +
 * streaming inputs through, forward the kernel's frames verbatim.
 *
 * No critic by design: the router specialist's short answer is what
 * the user sees, and a regenerate button covers the retry case. The
 * Kernel honours `withCritic: false` here.
 */
class StreamRouterResponse implements MultiAgentStreamAction
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
        $context->setAgentType(AgentType::Router);
        $context->setFacade($agent);
        $context->setAttachments($attachments);
        $context->setModelOverride($model);
        $context->setYieldPhase(function (array $phase) use (&$phases): string {
            return $this->yieldPhase($phases, $phase);
        });

        $generator = $this->kernel->stream($message, $context, withCritic: false);

        foreach ($generator as $frame) {
            yield $frame;

            if (connection_aborted()) {
                return;
            }
        }
    }
}
