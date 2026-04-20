<?php

namespace App\Ai\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Extends the vendor store with three behaviors:
 *
 *   1. `getLatestConversationMessages` filters to `status = 'completed'` so
 *      canceled turns never re-enter the prompt context.
 *   2. `storeUserMessage` / `storeAssistantMessage` cooperate with
 *      {@see PendingTurnTracker} — when a pending row was pre-inserted for
 *      this request, the middleware's success callback promotes it rather
 *      than inserting a duplicate.
 *   3. If no pending row exists (e.g. a brand-new conversation, where we
 *      skip pre-insert because no conversation id exists yet), behavior
 *      falls back to the parent's plain insert.
 */
class TrackedDatabaseConversationStore extends DatabaseConversationStore
{
    public function __construct(private readonly PendingTurnTracker $tracker) {}

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        return $this->tracker->completeUserMessage($conversationId)
            ?? parent::storeUserMessage($conversationId, $userId, $prompt);
    }

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        return $this->tracker->completeAssistantMessage($conversationId, $response)
            ?? parent::storeAssistantMessage($conversationId, $userId, $prompt, $response);
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record) {
                $toolCalls = collect(json_decode($record->tool_calls, true));
                $toolResults = collect(json_decode($record->tool_results, true));

                if ($record->role === 'user') {
                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    /** @var list<Message> $messages */
                    $messages = [];

                    $messages[] = new AssistantMessage(
                        $record->content ?: '',
                        $toolCalls->map(fn ($toolCall) => new ToolCall(
                            id: $toolCall['id'],
                            name: $toolCall['name'],
                            arguments: $toolCall['arguments'],
                            resultId: $toolCall['result_id'] ?? null,
                        ))
                    );

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(fn ($toolResult) => new ToolResult(
                                id: $toolResult['id'],
                                name: $toolResult['name'],
                                arguments: $toolResult['arguments'],
                                result: $toolResult['result'],
                                resultId: $toolResult['result_id'] ?? null,
                            ))
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
    }
}
