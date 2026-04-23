<?php

use App\Actions\Router\StreamRouterResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

/*
 * Narrow test for the DB-patching step that keeps the router's
 * phase strip visible across a page refresh. Testing the full SSE
 * pipeline would require simulating real agent streaming; the
 * row-patching behaviour is self-contained enough to exercise
 * directly via reflection, mirroring StreamResearchResponseTest.
 */

function invokeRouterPersistPhases(
    string $conversationId,
    Carbon $pivot,
    array $phases,
): void {
    $action = app(StreamRouterResponse::class);
    $method = new ReflectionMethod($action, 'persistPhases');
    $method->invoke($action, $conversationId, $pivot, $phases);
}

test('persists router phase list onto meta.phases of the latest assistant row', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'gpt-4o'],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokeRouterPersistPhases($conversation->id, $pivot, [
        [
            'key' => 'classifier',
            'label' => 'Classifying',
            'status' => 'complete',
            'category' => 'task',
            'confidence' => 0.91,
        ],
        [
            'key' => 'answer',
            'label' => 'Answering as task',
            'status' => 'complete',
        ],
    ]);

    $assistant->refresh();

    expect($assistant->meta['phases'])->toHaveCount(2);
    expect($assistant->meta['phases'][0]['key'])->toBe('classifier');
    expect($assistant->meta['phases'][0]['category'])->toBe('task');
    expect($assistant->meta['phases'][1]['key'])->toBe('answer');
    // Pre-existing meta keys (model) must be preserved.
    expect($assistant->meta['model'])->toBe('gpt-4o');
});

test('router phase persistence is a no-op for an empty phase list', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'gpt-4o'],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    $originalUpdatedAt = $assistant->updated_at;

    invokeRouterPersistPhases($conversation->id, $pivot, []);

    $assistant->refresh();

    expect($assistant->meta)->not->toHaveKey('phases');
    expect($assistant->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
