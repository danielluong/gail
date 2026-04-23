<?php

namespace App\Actions\Concerns;

use App\Ai\Agents\BaseAgent;
use App\Ai\Agents\MultiAgentFacade;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Shared streaming scaffold for chat-UI multi-agent workflows.
 *
 * Every multi-agent streaming action (Research, Router, and whatever
 * comes next) needs the same bootstrap + postamble around an inner
 * workflow-specific section: `set_time_limit(0)`, attachment prep,
 * pending-turn pre-insert, pivot timestamp, phase persistence,
 * regenerate variant linking, message_usage / conversation frames,
 * auto-title, error framing, and `[DONE]` terminator. This trait
 * owns all of that; the subclass only supplies the middle section
 * (phase emission + the specific pre- / main- / post-agent calls)
 * via {@see self::workflowFrames()}.
 *
 * Pair with {@see EmitsAgentPhases} — that trait provides
 * `yieldPhase` / `persistPhases` / `persistSiblingToolActivity`
 * which `workflowFrames()` implementations use to emit the chip-row
 * and (if the workflow has a tool-using sibling worker) patch its
 * tool activity onto the persisted assistant row.
 *
 * ### Assumed host properties
 *
 * Consumers must declare the following readonly properties on their
 * constructor (any visibility the subclass prefers). The trait
 * methods access them by name:
 *
 * - `AttachmentService     $attachments`
 * - `LinkAssistantVariant  $linkVariant`
 * - `ModelPricing          $pricing`
 * - `GenerateConversationTitle $generateTitle`
 * - `PendingTurnTracker    $pendingTurns`
 *
 * A subclass can add its own extra deps (e.g. `UniversalRouter`) on top.
 *
 * ### Typical subclass shape
 *
 *     class StreamMyWorkflow implements MultiAgentStreamAction
 *     {
 *         use EmitsAgentPhases, StreamsMultiAgentWorkflow;
 *
 *         public function __construct(
 *             private readonly AttachmentService $attachments,
 *             private readonly LinkAssistantVariant $linkVariant,
 *             private readonly ModelPricing $pricing,
 *             private readonly GenerateConversationTitle $generateTitle,
 *             private readonly PendingTurnTracker $pendingTurns,
 *         ) {}
 *
 *         protected function workflowFrames(BaseAgent $agent, string $message, array $attachments, ?string $model, Carbon $pivot, array &$phases): Generator
 *         {
 *             yield $this->yieldPhase($phases, ['key' => 'step', 'label' => 'Stepping', 'status' => 'running']);
 *             // ... workflow-specific streaming, connection_aborted checks, main $agent->stream() loop ...
 *             yield $this->yieldPhase($phases, ['key' => 'step', 'label' => 'Stepping', 'status' => 'complete']);
 *         }
 *     }
 *
 * The public `execute()` method is provided by the trait — subclasses
 * only supply the workflow-specific middle section in
 * {@see workflowFrames()}. Dispatch from ChatController happens via
 * the facade's {@see MultiAgentFacade::streamingActionClass()}.
 */
trait StreamsMultiAgentWorkflow
{
    /**
     * Workflow-specific middle section. Receives the facade agent,
     * the prepared user message (post-attachment-cleanup), the
     * attachments array, the resolved model, the stream pivot
     * timestamp, and a by-reference phases accumulator. Yields SSE
     * frames; uses `yieldPhase()` from EmitsAgentPhases to keep the
     * phases array and outgoing frames in lockstep.
     *
     * Implementations are responsible for any workflow-specific
     * persistence that needs to happen *inside* the stream (e.g.
     * Research's `persistSiblingToolActivity` for Researcher tool
     * calls). The trait's common postamble handles the rest
     * (phases, variants, usage, conversation, title).
     *
     * Return early on `connection_aborted()` to skip the postamble.
     *
     * @param  list<array<string, mixed>>  $phases
     */
    abstract protected function workflowFrames(
        BaseAgent $agent,
        string $message,
        array $attachments,
        ?string $model,
        Carbon $pivot,
        array &$phases,
    ): Generator;

    /**
     * Entry point used by ChatController. Sets up the long-running SSE
     * response and delegates the event body to {@see workflowStream()}.
     * Kept in the trait so every consuming action shares the exact same
     * bootstrap (no `set_time_limit` / `ignore_user_abort` drift) and
     * subclasses only own the workflow-specific middle section.
     *
     * @param  list<string>  $filePaths
     */
    public function execute(
        BaseAgent $agent,
        string $message,
        array $filePaths,
        ?string $model,
        ?int $projectId,
        bool $regenerate = false,
    ): StreamedResponse {
        set_time_limit(0);
        ignore_user_abort(true);

        return response()->stream(
            fn () => yield from $this->workflowStream($agent, $message, $filePaths, $model, $projectId, $regenerate),
            headers: ['Content-Type' => 'text/event-stream'],
        );
    }

    /**
     * The shared SSE body. Invoked by {@see execute()} — subclasses
     * don't call this directly.
     *
     * @param  list<string>  $filePaths
     */
    protected function workflowStream(
        BaseAgent $agent,
        string $message,
        array $filePaths,
        ?string $model,
        ?int $projectId,
        bool $regenerate,
    ): Generator {
        try {
            [
                'message' => $message,
                'attachments' => $attachments,
                'warnings' => $warnings,
            ] = $this->attachments->prepare($filePaths, $message);

            foreach ($warnings as $warning) {
                yield $this->frame(json_encode([
                    'type' => 'warning',
                    'message' => $warning,
                ]));
            }

            $pivot = Carbon::now();

            if (! $regenerate) {
                $this->beginPendingTurn($agent, $message, $attachments);
            }

            $phases = [];

            yield from $this->workflowFrames(
                $agent,
                $message,
                $attachments,
                $model,
                $pivot,
                $phases,
            );

            if (connection_aborted()) {
                return;
            }

            if ($conversationId = $agent->currentConversation()) {
                $this->tagConversationProject($conversationId, $projectId);

                $this->persistPhases($conversationId, $pivot, $phases);

                if ($regenerate) {
                    $this->linkVariant->execute($conversationId, $pivot);
                }

                if ($usageFrame = $this->buildMessageUsageFrame($conversationId, $pivot)) {
                    yield $this->frame($usageFrame);
                }

                yield $this->frame(json_encode([
                    'type' => 'conversation',
                    'conversation_id' => $conversationId,
                ]));

                $this->maybeGenerateTitle($conversationId, $regenerate);
            }
        } catch (Throwable $e) {
            Log::channel('ai')->error('multi-agent stream failed', [
                'model' => $model,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            yield $this->frame(json_encode([
                'type' => 'error',
                'message' => $e->getMessage(),
            ]));
        } finally {
            $this->pendingTurns->cancelRemaining();
        }

        yield "data: [DONE]\n\n";
    }

    /**
     * @param  list<Image>  $attachments
     */
    protected function beginPendingTurn(BaseAgent $agent, string $message, array $attachments): void
    {
        $conversationId = $agent->currentConversation();

        if ($conversationId === null) {
            return;
        }

        $this->pendingTurns->beginTurn(
            conversationId: $conversationId,
            userId: $agent->conversationParticipant()?->id,
            agentClass: $agent::class,
            content: $message,
            attachmentsJson: collect($attachments)->toJson(),
        );
    }

    protected function tagConversationProject(string $conversationId, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        Conversation::where('id', $conversationId)
            ->whereNull('project_id')
            ->update(['project_id' => $projectId]);
    }

    protected function buildMessageUsageFrame(string $conversationId, Carbon $pivot): ?string
    {
        $latest = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $pivot)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'usage', 'meta']);

        if ($latest === null) {
            return null;
        }

        $latestUserId = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->where('created_at', '>=', $pivot)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        $usage = is_array($latest->usage) && $latest->usage !== [] ? $latest->usage : null;
        $modelUsed = is_array($latest->meta) ? ($latest->meta['model'] ?? null) : null;

        return json_encode([
            'type' => 'message_usage',
            'message_id' => $latest->id,
            'user_message_id' => $latestUserId,
            'usage' => $usage,
            'cost' => $this->pricing->costFor(
                is_string($modelUsed) ? $modelUsed : null,
                $usage,
            ),
        ]);
    }

    protected function maybeGenerateTitle(string $conversationId, bool $regenerate): void
    {
        if ($regenerate) {
            return;
        }

        $assistantCount = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNull('variant_of')
            ->count();

        if ($assistantCount !== 1) {
            return;
        }

        try {
            $this->generateTitle->execute($conversationId);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('multi-agent auto-title skipped', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function frame(string $payload): string
    {
        return "data: {$payload}\n\n";
    }
}
