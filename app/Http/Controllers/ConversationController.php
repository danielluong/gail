<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\BranchConversation;
use App\Actions\Conversations\ExportConversation;
use App\Http\Requests\UpdateConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function search(Request $request)
    {
        $query = trim($request->input('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $conversations = Conversation::query()
            ->matchingQuery($query)
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'project_id', 'updated_at']);

        return response()->json($conversations);
    }

    public function export(Request $request, Conversation $conversation, ExportConversation $export)
    {
        return $export->execute($conversation, $request->input('format', 'markdown'));
    }

    public function messages(Conversation $conversation)
    {
        // `created_at` is stored with second precision, so tie-break by
        // `id` to keep user/assistant pairs in insertion order. Laravel's
        // ordered UUIDs (and the ones we mint in BranchConversation) are
        // time-sortable, so this works as a secondary chronological key.
        $all = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $regensByOriginal = $all
            ->where('role', 'assistant')
            ->filter(fn (ConversationMessage $m) => $m->variant_of !== null)
            ->groupBy('variant_of');

        $collapsed = $all
            ->filter(fn (ConversationMessage $m) => $m->variant_of === null)
            ->map(function (ConversationMessage $m) use ($regensByOriginal) {
                $base = $m->toChatUiArray();

                if ($m->role !== 'assistant') {
                    return $base;
                }

                $regens = $regensByOriginal->get($m->id);

                if ($regens === null || $regens->isEmpty()) {
                    return $base;
                }

                $allVariants = collect([$m])
                    ->concat($regens->sortBy('created_at'))
                    ->map->toChatUiArray()
                    ->values()
                    ->all();

                $latest = array_pop($allVariants);

                return array_merge($base, [
                    'content' => $latest['content'],
                    'attachments' => $latest['attachments'],
                    'toolCalls' => $latest['toolCalls'],
                    'phases' => $latest['phases'],
                    'model' => $latest['model'],
                    'usage' => $latest['usage'],
                    'cost' => $latest['cost'],
                    'created_at' => $latest['created_at'],
                    'variants' => $allVariants,
                ]);
            })
            ->values()
            ->all();

        return response()->json($collapsed);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation)
    {
        $conversation->update($request->validated());

        return response()->noContent();
    }

    public function destroy(Conversation $conversation)
    {
        $conversation->delete();

        return response()->noContent();
    }

    public function branch(Request $request, Conversation $conversation, BranchConversation $branch)
    {
        $validated = $request->validate([
            'message_id' => ['required', 'string', 'exists:agent_conversation_messages,id'],
        ]);

        $new = $branch->execute($conversation, $validated['message_id']);

        return response()->json([
            'id' => $new->id,
            'title' => $new->title,
            'project_id' => $new->project_id,
            'parent_id' => $new->parent_id,
            'is_pinned' => $new->is_pinned,
            'updated_at' => $new->updated_at,
        ], 201);
    }
}
