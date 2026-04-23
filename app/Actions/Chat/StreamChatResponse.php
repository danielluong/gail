<?php

namespace App\Actions\Chat;

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
 * SSE streaming action for every single-agent chat (Chat / Limerick /
 * MySQLDatabase / any plain {@see BaseAgent}). After the Kernel
 * migration this is a pure composition: derive the user's agent type
 * from the resolved BaseAgent, stamp it on the {@see KernelContext}
 * along with the facade + per-request streaming inputs, hand the input
 * to {@see AgentKernel::stream()}, forward the frames verbatim.
 *
 * No Critic phase: the chat UI single-agent flows have always run
 * critic-free. The Kernel honours `withCritic: false` here.
 */
class StreamChatResponse implements MultiAgentStreamAction
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
        $context->setAgentType(AgentType::fromAgentClass($agent::class) ?? AgentType::Default);
        $context->setFacade($agent);
        $context->setAttachments($attachments);
        $context->setModelOverride($model);

        $generator = $this->kernel->stream($message, $context, withCritic: false);

        foreach ($generator as $frame) {
            yield $frame;

            if (connection_aborted()) {
                return;
            }
        }
    }
}
