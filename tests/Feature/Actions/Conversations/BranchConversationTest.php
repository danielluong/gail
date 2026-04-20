<?php

use App\Actions\Conversations\BranchConversation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Project;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('branch copies every message up to and including the branch point', function () {
    $conversation = Conversation::factory()->create(['title' => 'Original']);

    $first = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'first',
        'created_at' => now()->subMinutes(3),
    ]);
    $second = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'second',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'third',
        'created_at' => now()->subMinute(),
    ]);

    $branch = (new BranchConversation)->execute($conversation, $second->id);

    expect($branch->title)->toBe('Original');
    expect($branch->parent_id)->toBe($conversation->id);
    expect($branch->id)->not->toBe($conversation->id);

    $branchContents = $branch->messages()->orderBy('created_at')->pluck('content')->all();
    expect($branchContents)->toBe(['first', 'second']);

    expect(ConversationMessage::where('id', $first->id)->exists())->toBeTrue();
    expect($conversation->fresh()->messages()->count())->toBe(3);
});

test('branch preserves the source project assignment', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => $project->id]);
    $message = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
    ]);

    $branch = (new BranchConversation)->execute($conversation, $message->id);

    expect($branch->project_id)->toBe($project->id);
});

test('branch throws NotFoundHttpException when the message is not in the conversation', function () {
    $conversation = Conversation::factory()->create();
    $other = Conversation::factory()->create();
    $foreign = ConversationMessage::factory()->create(['conversation_id' => $other->id]);

    (new BranchConversation)->execute($conversation, $foreign->id);
})->throws(NotFoundHttpException::class);

test('branch copies persisted tool_calls on the branch-point message', function () {
    $conversation = Conversation::factory()->create();

    $message = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'looking that up',
        'tool_calls' => [
            ['id' => 'call_x', 'name' => 'WebSearch', 'arguments' => ['query' => 'pizza']],
        ],
        'tool_results' => [
            '0' => ['id' => 'call_x', 'name' => 'WebSearch', 'result' => 'results...'],
        ],
    ]);

    $branch = (new BranchConversation)->execute($conversation, $message->id);

    $copy = $branch->messages()->first();

    expect($copy->tool_calls)->toBe([
        ['id' => 'call_x', 'name' => 'WebSearch', 'arguments' => ['query' => 'pizza']],
    ]);
    expect($copy->tool_results)->toBe([
        '0' => ['id' => 'call_x', 'name' => 'WebSearch', 'result' => 'results...'],
    ]);
});
