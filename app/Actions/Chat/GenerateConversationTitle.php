<?php

namespace App\Actions\Chat;

use App\Ai\Agents\TitlerAgent;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateConversationTitle
{
    /**
     * Summarize the first turn of a conversation into a short title and
     * save it. Intended to run once per conversation, right after the
     * first assistant reply: we have the richest signal then (a question
     * + a real answer) and avoid clobbering anything the user later
     * renames manually.
     *
     * laravel/ai already writes an initial title on storeConversation(),
     * but that call only sees the user's prompt. Running after the full
     * turn produces noticeably better titles, especially for terse
     * questions like "explain this code" where the assistant's reply
     * carries the actual topic.
     *
     * Failures are logged and swallowed — a bad title should never take
     * down the chat stream.
     */
    public function execute(string $conversationId): void
    {
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null) {
            return;
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'role', 'content']);

        $firstUser = $messages->firstWhere('role', 'user');
        $firstAssistant = $messages->firstWhere('role', 'assistant');

        if ($firstUser === null || $firstAssistant === null) {
            return;
        }

        $title = $this->summarize(
            (string) $firstUser->content,
            (string) $firstAssistant->content,
        );

        if ($title === null) {
            return;
        }

        $conversation->update(['title' => $title]);
    }

    private function summarize(string $userMessage, string $assistantMessage): ?string
    {
        $prompt = 'User: '.Str::limit($userMessage, 1_000)
            ."\n\nAssistant: ".Str::limit($assistantMessage, 1_000);

        try {
            $response = TitlerAgent::make()->prompt($prompt);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('auto-title failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $title = $this->clean($response->text);

        return $title !== '' ? $title : null;
    }

    /**
     * Strip wrapping quotes, stray trailing punctuation, and trim to the
     * first line. Models sometimes prepend "Title: " or wrap the answer
     * in quotes despite the instructions.
     */
    private function clean(string $raw): string
    {
        $line = trim(strtok($raw, "\n") ?: '');
        $line = trim($line, " \t\n\r\0\x0B\"'`.,;:");
        $line = preg_replace('/^(title|summary)\s*:\s*/i', '', $line) ?? $line;
        $line = trim($line, " \t\n\r\0\x0B\"'`.,;:");

        return Str::limit($line, 80, '');
    }
}
