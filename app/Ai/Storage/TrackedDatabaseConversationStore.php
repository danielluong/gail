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
                    /*
                     * Two reasons a persisted assistant-with-tool_calls
                     * row can't be replayed as-is:
                     *
                     *   (a) Orphan tool_calls — more tool_calls than
                     *       matching tool_results. OpenAI's chat API
                     *       requires 1:1 pairing between function_call
                     *       and function_call_output entries.
                     *   (b) Missing result_id — the OpenAI Responses
                     *       API maps ToolCall::resultId to `call_id`,
                     *       the field it uses to match function_call
                     *       and function_call_output. Null or missing
                     *       values fail validation with HTTP 400.
                     *
                     * Both symptoms come from rows written before the
                     * persist-side fix existed. We sanitise on read so
                     * existing conversations keep working without a
                     * migration — the worst case is the model forgets
                     * that tools ran, but the turn completes.
                     */
                    $resultIds = $toolResults
                        ->pluck('id')
                        ->filter(fn ($id) => $id !== null)
                        ->all();
                    $toolCalls = $toolCalls
                        ->filter(fn ($tc) => isset($tc['id'])
                            && in_array($tc['id'], $resultIds, true)
                            && ! empty($tc['result_id'])
                        )
                        ->values();

                    if ($toolCalls->isEmpty()) {
                        return [new AssistantMessage($record->content ?: '')];
                    }

                    $callIds = $toolCalls->pluck('id')->all();
                    $toolResults = $toolResults
                        ->filter(fn ($tr) => isset($tr['id'])
                            && in_array($tr['id'], $callIds, true)
                            && ! empty($tr['result_id'])
                        )
                        ->values();

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
