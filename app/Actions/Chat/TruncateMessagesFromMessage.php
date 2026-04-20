<?php

namespace App\Actions\Chat;

use App\Models\ConversationMessage;

class TruncateMessagesFromMessage
{
    /**
     * Remove the given message and every later message in the conversation,
     * preserving chronological order. Returns the number of messages
     * removed (0 if the message does not belong to the conversation).
     *
     * Referencing by id rather than array position keeps the wire contract
     * stable if the client's in-memory view drifts from the persisted
     * order — a positional index would silently truncate the wrong turn.
     */
    public function execute(string $conversationId, string $messageId): int
    {
        $pivot = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('id', $messageId)
            ->value('created_at');

        if ($pivot === null) {
            return 0;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('created_at', '>=', $pivot)
            ->delete();
    }
}
