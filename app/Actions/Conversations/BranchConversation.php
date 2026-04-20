<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BranchConversation
{
    /**
     * Create a new conversation containing every message up to and
     * including the given branch point. The new conversation carries
     * the same title and project, and records the source conversation
     * as its parent.
     */
    public function execute(Conversation $source, string $branchMessageId): Conversation
    {
        $messages = $source->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $branchPoint = $messages->firstWhere('id', $branchMessageId);

        if ($branchPoint === null) {
            throw new NotFoundHttpException('Message does not belong to this conversation.');
        }

        $messagesToCopy = $messages
            ->takeUntil(fn (ConversationMessage $message) => $message->id === $branchPoint->id)
            ->push($branchPoint);

        return DB::transaction(function () use ($source, $messagesToCopy) {
            $branch = new Conversation;
            $branch->id = Str::orderedUuid()->toString();
            $branch->fill([
                'title' => $source->title,
                'project_id' => $source->project_id,
                'user_id' => $source->user_id,
                'parent_id' => $source->id,
            ])->save();

            $idMap = [];

            foreach ($messagesToCopy as $message) {
                // Copy raw DB attributes through setRawAttributes so that
                // JSON-cast columns (attachments, tool_calls, tool_results,
                // usage, meta) are not re-encoded by their casts when the
                // already-serialized strings pass through setAttribute().
                // Ordered UUIDs act as a tie-breaker when multiple rows
                // share the same second-precision `created_at` on load.
                $attributes = $message->getAttributes();
                $newId = Str::orderedUuid()->toString();
                $idMap[$message->id] = $newId;
                $attributes['id'] = $newId;
                $attributes['conversation_id'] = $branch->id;

                // Keep the variant carousel intact on the branch: rewrite
                // variant_of to point at the copied original's new id, or
                // null it out if the original was cut off by the branch.
                if (! empty($attributes['variant_of'])) {
                    $attributes['variant_of'] = $idMap[$attributes['variant_of']] ?? null;
                }

                $copy = new ConversationMessage;
                $copy->timestamps = false;
                $copy->setRawAttributes($attributes);
                $copy->save();
            }

            return $branch;
        });
    }
}
