<?php

namespace App\Actions\Chat;

use App\Models\ConversationMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class LinkAssistantVariant
{
    /**
     * After a regenerate stream completes, the `RememberConversation`
     * middleware has just inserted a duplicate user message followed by
     * a fresh assistant message. Drop the duplicate user row and link
     * the new assistant row back to the original it replaces, so the
     * variant carousel picks it up on reload.
     *
     * `$since` scopes the fix to rows this stream wrote; the original is
     * resolved as the latest assistant message with no `variant_of` in
     * the conversation up to that point.
     */
    public function execute(string $conversationId, CarbonInterface $since): void
    {
        DB::transaction(function () use ($conversationId, $since) {
            $original = ConversationMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->whereNull('variant_of')
                ->where('created_at', '<=', $since)
                ->orderByDesc('created_at')
                ->first();

            if ($original === null) {
                return;
            }

            $newMessages = ConversationMessage::query()
                ->where('conversation_id', $conversationId)
                ->where('created_at', '>', $since)
                ->orderBy('created_at')
                ->get();

            $duplicateUser = $newMessages->firstWhere('role', 'user');
            $newAssistant = $newMessages->firstWhere('role', 'assistant');

            $duplicateUser?->delete();
            $newAssistant?->update(['variant_of' => $original->id]);
        });
    }
}
