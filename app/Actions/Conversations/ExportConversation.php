<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class ExportConversation
{
    /**
     * Render a conversation export in either markdown (default) or
     * JSON format, returning an HTTP response with a download header.
     */
    public function execute(Conversation $conversation, string $format = 'markdown'): BaseResponse
    {
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get(['role', 'content', 'created_at']);

        $slug = str($conversation->title)->slug();

        if ($format === 'json') {
            return response()->json([
                'title' => $conversation->title,
                'exported_at' => now()->toIso8601String(),
                'messages' => $messages->map(fn ($m) => [
                    'role' => $m->role,
                    'content' => $m->content,
                    'created_at' => $m->created_at,
                ]),
            ], headers: [
                'Content-Disposition' => "attachment; filename=\"{$slug}.json\"",
            ]);
        }

        $markdown = "# {$conversation->title}\n\n";

        foreach ($messages as $message) {
            $label = $message->role === 'user' ? 'User' : 'Assistant';
            $markdown .= "## {$label}\n\n{$message->content}\n\n---\n\n";
        }

        return new Response($markdown, headers: [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => "attachment; filename=\"{$slug}.md\"",
        ]);
    }
}
