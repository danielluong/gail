<?php

namespace App\Actions\Chat;

use App\Ai\Agents\BaseAgent;
use App\Ai\Storage\PendingTurnTracker;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AttachmentService;
use App\Support\ModelPricing;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StreamChatResponse
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkAssistantVariant $linkVariant,
        private readonly ModelPricing $pricing,
        private readonly GenerateConversationTitle $generateTitle,
        private readonly PendingTurnTracker $pendingTurns,
    ) {}

    /**
     * Turn a resolved agent + message + attachments into an SSE stream
     * response. The stream yields text deltas from the agent, a final
     * `conversation` event once the agent has persisted the conversation,
     * and always terminates with [DONE].
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

        // Keep PHP alive if the client drops mid-stream so the `finally`
        // below runs and flips any pending rows to `canceled` — otherwise
        // those rows would be stuck in `pending` forever and the UI
        // couldn't distinguish a dropped turn from an in-flight one.
        ignore_user_abort(true);

        return response()->stream(
            fn () => yield from $this->frames($agent, $message, $filePaths, $model, $projectId, $regenerate),
            headers: ['Content-Type' => 'text/event-stream'],
        );
    }

    /**
     * @param  list<string>  $filePaths
     */
    private function frames(
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

            // Skip pre-insert for regenerate — LinkAssistantVariant assumes
            // the middleware writes a fresh user row to delete; inserting
            // our own pending rows would tangle that flow, and a canceled
            // regenerate shouldn't surface as a new user bubble anyway.
            if (! $regenerate) {
                $this->beginPendingTurn($agent, $message, $attachments);
            }

            foreach ($agent->stream($message, $attachments, model: $model) as $event) {
                yield $this->frame((string) $event);

                // Stop iterating once the client is gone so the middleware's
                // success callback never runs — the finally below will flip
                // the pre-inserted rows to `canceled`, matching the UI's
                // local state after the user aborted.
                if (connection_aborted()) {
                    break;
                }
            }

            if (connection_aborted()) {
                return;
            }

            if ($conversationId = $agent->currentConversation()) {
                $this->tagConversationProject($conversationId, $projectId);

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
            Log::channel('ai')->error('chat stream failed', [
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
            // Any turn still in `pending` here never reached the
            // middleware's success callback — mark it canceled so the
            // next prompt skips it but the UI still shows it.
            $this->pendingTurns->cancelRemaining();
        }

        yield "data: [DONE]\n\n";
    }

    /**
     * Pre-insert a pending user + assistant pair so a canceled mid-stream
     * turn survives a page refresh. Only runs when a conversation already
     * exists — first messages have no conversation row yet, so there is
     * nothing to attach pending rows to.
     *
     * @param  list<Image>  $attachments
     */
    private function beginPendingTurn(BaseAgent $agent, string $message, array $attachments): void
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

    private function tagConversationProject(string $conversationId, ?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        Conversation::where('id', $conversationId)
            ->whereNull('project_id')
            ->update(['project_id' => $projectId]);
    }

    /**
     * Build the payload for the message_usage SSE event, letting the UI
     * render tokens and cost on the assistant bubble without a refetch.
     * Also carries the persisted user/assistant ids so the client can
     * swap its optimistic numeric ids for the real database ids — that
     * in turn unlocks Edit and Branch actions without a page refresh.
     *
     * `user_message_id` is null for regenerates (LinkAssistantVariant
     * has already removed the duplicate user row by the time we query).
     *
     * Returns null when nothing was persisted (unusual — agent->stream
     * didn't persist a new row this turn).
     */
    private function buildMessageUsageFrame(string $conversationId, Carbon $pivot): ?string
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

    /**
     * Kick off title generation on the very first assistant turn. laravel/ai
     * writes a placeholder title from the user's prompt alone; regenerating
     * here with both sides of the exchange produces sharper titles and
     * avoids clobbering anything the user renamed later.
     */
    private function maybeGenerateTitle(string $conversationId, bool $regenerate): void
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
            Log::channel('ai')->warning('auto-title skipped', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function frame(string $payload): string
    {
        return "data: {$payload}\n\n";
    }
}
