<?php

namespace App\Ai\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Tracks the pre-inserted user / assistant rows for the current request so
 * canceled turns leave a visible record in the conversation history without
 * polluting the prompt context on the next request.
 *
 * A pending pair is created before the stream begins; on a successful stream
 * the store marks both rows `completed` (picked up from the middleware's
 * `.then()` callback). If the stream is interrupted, `cancelRemaining()`
 * flips whatever is still pending to `canceled`.
 */
class PendingTurnTracker
{
    private ?string $conversationId = null;

    private ?string $userMessageId = null;

    private ?string $assistantMessageId = null;

    /**
     * Pre-insert a pending user + assistant pair for the given conversation.
     * Safe to call at most once per request; subsequent calls overwrite the
     * tracked IDs.
     */
    public function beginTurn(
        string $conversationId,
        string|int|null $userId,
        string $agentClass,
        string $content,
        string $attachmentsJson,
    ): void {
        $now = now();
        $userMessageId = (string) Str::uuid7();
        $assistantMessageId = (string) Str::uuid7();

        DB::table('agent_conversation_messages')->insert([
            [
                'id' => $userMessageId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'agent' => $agentClass,
                'role' => 'user',
                'status' => 'pending',
                'content' => $content,
                'attachments' => $attachmentsJson,
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $assistantMessageId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'agent' => $agentClass,
                'role' => 'assistant',
                'status' => 'pending',
                'content' => '',
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->conversationId = $conversationId;
        $this->userMessageId = $userMessageId;
        $this->assistantMessageId = $assistantMessageId;
    }

    /**
     * Promote the pending user row for the given conversation to completed.
     * Returns the row's id, or null if we are not tracking a pending user
     * message for this conversation (i.e. a fresh insert should happen).
     */
    public function completeUserMessage(string $conversationId): ?string
    {
        if ($this->conversationId !== $conversationId || $this->userMessageId === null) {
            return null;
        }

        $id = $this->userMessageId;

        DB::table('agent_conversation_messages')
            ->where('id', $id)
            ->update(['status' => 'completed', 'updated_at' => now()]);

        $this->userMessageId = null;

        return $id;
    }

    /**
     * Fill in the pending assistant row with the finished response and mark
     * it completed. Returns the row's id, or null if we are not tracking a
     * pending assistant message for this conversation.
     */
    public function completeAssistantMessage(string $conversationId, AgentResponse $response): ?string
    {
        if ($this->conversationId !== $conversationId || $this->assistantMessageId === null) {
            return null;
        }

        $id = $this->assistantMessageId;

        DB::table('agent_conversation_messages')
            ->where('id', $id)
            ->update([
                'status' => 'completed',
                'content' => $response->text,
                'tool_calls' => json_encode($response->toolCalls),
                'tool_results' => json_encode($response->toolResults),
                'usage' => json_encode($response->usage),
                'meta' => json_encode($response->meta),
                'updated_at' => now(),
            ]);

        $this->assistantMessageId = null;

        return $id;
    }

    /**
     * Mark any rows still pending for this request as canceled. Runs in the
     * stream's finally block so aborted turns are distinguishable from
     * completed ones.
     */
    public function cancelRemaining(): void
    {
        $ids = array_filter([$this->userMessageId, $this->assistantMessageId]);

        if ($ids === []) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'canceled', 'updated_at' => now()]);

        $this->userMessageId = null;
        $this->assistantMessageId = null;
    }
}
