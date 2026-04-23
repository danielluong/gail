<?php

use App\Actions\Research\StreamResearchResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;

/*
 * Narrow test for the DB-patching step that keeps the Researcher's
 * tool calls visible across a page refresh. Testing the full SSE
 * pipeline would require simulating tool_call stream events, which
 * the laravel/ai fake gateway doesn't emit — but the row-patching
 * itself is a self-contained concern worth covering directly.
 */

function invokePersistResearcherToolCalls(
    string $conversationId,
    Carbon $pivot,
    array $toolCalls,
    array $toolResults,
): void {
    $action = app(StreamResearchResponse::class);
    $method = new ReflectionMethod($action, 'persistSiblingToolActivity');
    $method->invoke($action, $conversationId, $pivot, $toolCalls, $toolResults);
}

function invokePersistPhases(
    string $conversationId,
    Carbon $pivot,
    array $phases,
): void {
    $action = app(StreamResearchResponse::class);
    $method = new ReflectionMethod($action, 'persistPhases');
    $method->invoke($action, $conversationId, $pivot, $phases);
}

test('persists Researcher tool calls onto the latest assistant row', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [],
        'tool_results' => [],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokePersistResearcherToolCalls(
        $conversation->id,
        $pivot,
        [
            [
                'id' => 'call_1',
                'name' => 'WebSearchTool',
                'arguments' => ['query' => 'solar vs nuclear'],
            ],
            [
                'id' => 'call_2',
                'name' => 'FetchPageTool',
                'arguments' => ['url' => 'https://example.com'],
            ],
        ],
        [
            [
                'id' => 'call_1',
                'name' => 'WebSearchTool',
                'arguments' => ['query' => 'solar vs nuclear'],
                'result' => '{"results":[...]}',
            ],
            [
                'id' => 'call_2',
                'name' => 'FetchPageTool',
                'arguments' => ['url' => 'https://example.com'],
                'result' => 'fetched text',
            ],
        ],
    );

    $assistant->refresh();

    expect($assistant->tool_calls)->toHaveCount(2);
    expect($assistant->tool_calls[0])->toMatchArray([
        'id' => 'call_1',
        'name' => 'WebSearchTool',
    ]);
    expect($assistant->tool_calls[0]['arguments'])->toMatchArray([
        'query' => 'solar vs nuclear',
    ]);

    expect($assistant->tool_results)->toHaveCount(2);
    // name + arguments MUST be on tool_results so the laravel/ai
    // ConversationStore can replay the turn when the user regenerates
    // or continues with a different agent; omitting them was the
    // "Undefined array key 'name'" regression.
    expect($assistant->tool_results[0])->toMatchArray([
        'id' => 'call_1',
        'name' => 'WebSearchTool',
        'result' => '{"results":[...]}',
    ]);
    expect($assistant->tool_results[0]['arguments'])->toMatchArray([
        'query' => 'solar vs nuclear',
    ]);
});

test('prepends Researcher activity so it renders before any existing entries', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [
            ['id' => 'editor_1', 'name' => 'FutureEditorTool', 'arguments' => []],
        ],
        'tool_results' => [
            [
                'id' => 'editor_1',
                'name' => 'FutureEditorTool',
                'arguments' => [],
                'result' => 'editor output',
            ],
        ],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokePersistResearcherToolCalls(
        $conversation->id,
        $pivot,
        [['id' => 'rsch_1', 'name' => 'WebSearchTool', 'arguments' => []]],
        [[
            'id' => 'rsch_1',
            'name' => 'WebSearchTool',
            'arguments' => [],
            'result' => 'search done',
        ]],
    );

    $assistant->refresh();

    expect($assistant->tool_calls)->toHaveCount(2);
    // Researcher first (call_1 was renamed rsch_1), then the pre-existing editor entry.
    expect($assistant->tool_calls[0]['id'])->toBe('rsch_1');
    expect($assistant->tool_calls[1]['id'])->toBe('editor_1');
});

test('is a no-op when the Researcher produced no tool activity', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [],
        'tool_results' => [],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    $originalUpdatedAt = $assistant->updated_at;

    invokePersistResearcherToolCalls($conversation->id, $pivot, [], []);

    $assistant->refresh();

    expect($assistant->tool_calls)->toBe([]);
    expect($assistant->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

test('ignores assistant rows that predate the stream pivot', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    // Row from a previous turn should NOT be touched.
    $earlier = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [],
        'tool_results' => [],
        'created_at' => $pivot->copy()->subMinute(),
    ]);

    invokePersistResearcherToolCalls(
        $conversation->id,
        $pivot,
        [['id' => 'call_1', 'name' => 'WebSearchTool', 'arguments' => []]],
        [[
            'id' => 'call_1',
            'name' => 'WebSearchTool',
            'arguments' => [],
            'result' => 'result',
        ]],
    );

    $earlier->refresh();

    expect($earlier->tool_calls)->toBe([]);
});

test('persisted Researcher tool_results round-trip through laravel/ai conversation store', function () {
    /*
     * Regression for "Undefined array key 'name'" on regenerate /
     * continue-with-different-agent. The symptom was a crash inside
     * DatabaseConversationStore::getLatestConversationMessages() the
     * second time an agent continued a conversation containing a
     * research turn; root cause was the persisted tool_results rows
     * missing the `name` and `arguments` keys that laravel/ai
     * assumes are present. This asserts the round-trip works on any
     * row we write.
     */
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    // Pre-insert a user row so the store has something to replay.
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'solar vs nuclear',
        'created_at' => $pivot->copy()->subSecond(),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Editor answer',
        'tool_calls' => [],
        'tool_results' => [],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokePersistResearcherToolCalls(
        $conversation->id,
        $pivot,
        [[
            'id' => 'call_1',
            'name' => 'WebSearchTool',
            'arguments' => ['query' => 'solar vs nuclear'],
            'result_id' => 'fc_1',
        ]],
        [[
            'id' => 'call_1',
            'name' => 'WebSearchTool',
            'arguments' => ['query' => 'solar vs nuclear'],
            'result' => '{"results":[]}',
            'result_id' => 'fc_1',
        ]],
    );

    $store = app(ConversationStore::class);

    // The bug would throw ErrorException here on the
    // $toolResult['name'] lookup. Loading the messages must succeed
    // and produce both the AssistantMessage and the ToolResultMessage
    // the downstream prompt builder expects.
    $messages = $store->getLatestConversationMessages($conversation->id, 100);

    expect($messages)->not->toBeEmpty();

    $assistantMessage = $messages->first(
        fn ($m) => $m instanceof AssistantMessage,
    );

    expect($assistantMessage)->not->toBeNull();

    $toolResultMessage = $messages->first(
        fn ($m) => $m instanceof ToolResultMessage,
    );

    expect($toolResultMessage)->not->toBeNull();
});

test('persists phase list onto meta.phases of the latest assistant row', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'gpt-4o'],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokePersistPhases($conversation->id, $pivot, [
        ['key' => 'researcher', 'label' => 'Researching', 'status' => 'complete'],
        ['key' => 'editor', 'label' => 'Editing', 'status' => 'complete'],
        [
            'key' => 'critic',
            'label' => 'Reviewing',
            'status' => 'complete',
            'approved' => true,
            'confidence' => 'high',
        ],
    ]);

    $assistant->refresh();

    expect($assistant->meta['phases'])->toHaveCount(3);
    expect($assistant->meta['phases'][0]['key'])->toBe('researcher');
    expect($assistant->meta['phases'][2])->toMatchArray([
        'key' => 'critic',
        'approved' => true,
        'confidence' => 'high',
    ]);
    // Existing meta keys (e.g. model) must not be dropped.
    expect($assistant->meta['model'])->toBe('gpt-4o');
});

test('is a no-op for phases when the list is empty', function () {
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'gpt-4o'],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    $originalUpdatedAt = $assistant->updated_at;

    invokePersistPhases($conversation->id, $pivot, []);

    $assistant->refresh();

    expect($assistant->meta)->not->toHaveKey('phases');
    expect($assistant->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

test('toChatUiArray surfaces meta.phases at the top level', function () {
    $conversation = Conversation::factory()->create();

    $message = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => [
            'model' => 'gpt-4o',
            'phases' => [
                ['key' => 'researcher', 'label' => 'Researching', 'status' => 'complete'],
                ['key' => 'editor', 'label' => 'Editing', 'status' => 'complete'],
            ],
        ],
    ]);

    $ui = $message->toChatUiArray();

    expect($ui['phases'])->toHaveCount(2);
    expect($ui['phases'][0]['key'])->toBe('researcher');
    expect($ui['model'])->toBe('gpt-4o');
});

test('toChatUiArray returns an empty phases array when none are persisted', function () {
    $conversation = Conversation::factory()->create();

    $message = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'gpt-4o'],
    ]);

    $ui = $message->toChatUiArray();

    expect($ui['phases'])->toBe([]);
});

test('persist drops orphan tool_calls that have no matching tool_result', function () {
    /*
     * Regression for regenerate → "HTTP request returned status code 400".
     * OpenAI rejects any assistant message whose tool_calls are not all
     * matched by subsequent tool messages. If the Researcher hits its
     * MaxSteps cap mid-loop, the emitted tool_calls can exceed the
     * tool_results by one or more; the persistence helper has to drop
     * those orphans before writing so the next turn's history replay
     * doesn't crash the provider.
     */
    $conversation = Conversation::factory()->create();
    $pivot = Carbon::now();

    $assistant = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [],
        'tool_results' => [],
        'created_at' => $pivot->copy()->addMillisecond(),
    ]);

    invokePersistResearcherToolCalls(
        $conversation->id,
        $pivot,
        // Three tool_calls but only one tool_result — the two orphans
        // must be dropped.
        [
            ['id' => 'call_1', 'name' => 'WebSearchTool', 'arguments' => [], 'result_id' => 'fc_1'],
            ['id' => 'call_2_orphan', 'name' => 'WebSearchTool', 'arguments' => [], 'result_id' => 'fc_2'],
            ['id' => 'call_3_orphan', 'name' => 'FetchPageTool', 'arguments' => [], 'result_id' => 'fc_3'],
        ],
        [
            [
                'id' => 'call_1',
                'name' => 'WebSearchTool',
                'arguments' => [],
                'result' => 'result_1',
                'result_id' => 'fc_1',
            ],
        ],
    );

    $assistant->refresh();

    expect($assistant->tool_calls)->toHaveCount(1);
    expect($assistant->tool_calls[0]['id'])->toBe('call_1');
    expect($assistant->tool_calls[0]['result_id'])->toBe('fc_1');
    expect($assistant->tool_results)->toHaveCount(1);
    expect($assistant->tool_results[0]['id'])->toBe('call_1');
});

test('conversation store strips orphan tool_calls on read so legacy rows don\'t crash replay', function () {
    /*
     * The persist-side filter protects new rows, but older rows
     * written before that filter existed can still contain orphan
     * tool_calls. Sanitising on read means a regenerate on a
     * pre-existing conversation still works without a migration.
     */
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'q',
        'status' => 'completed',
        'created_at' => now()->subSeconds(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'answer',
        'status' => 'completed',
        'tool_calls' => [
            ['id' => 'paired', 'name' => 'WebSearchTool', 'arguments' => [], 'result_id' => 'fc_paired'],
            ['id' => 'orphan', 'name' => 'WebSearchTool', 'arguments' => [], 'result_id' => 'fc_orphan'],
        ],
        'tool_results' => [
            [
                'id' => 'paired',
                'name' => 'WebSearchTool',
                'arguments' => [],
                'result' => 'paired result',
                'result_id' => 'fc_paired',
            ],
        ],
        'created_at' => now()->subSecond(),
    ]);

    $store = app(ConversationStore::class);

    $messages = $store->getLatestConversationMessages($conversation->id, 100);

    $assistantMessage = $messages->first(fn ($m) => $m instanceof AssistantMessage);
    $toolResultMessage = $messages->first(fn ($m) => $m instanceof ToolResultMessage);

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->toolCalls)->toHaveCount(1);
    expect($assistantMessage->toolCalls[0]->id)->toBe('paired');

    expect($toolResultMessage)->not->toBeNull();
    expect($toolResultMessage->toolResults)->toHaveCount(1);
    expect($toolResultMessage->toolResults[0]->id)->toBe('paired');
});

test('conversation store drops tool_calls missing result_id — legacy rows that predate the call_id fix', function () {
    /*
     * Rows written before the persist-side fix have result_id = null
     * on every entry. Sending those to OpenAI fails with HTTP 400
     * because the Responses API requires a non-null call_id on both
     * the function_call and function_call_output. The read-side
     * filter drops the whole tool sequence in that case so the
     * history still replays cleanly as a plain assistant message.
     */
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'q',
        'status' => 'completed',
        'created_at' => now()->subSeconds(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'answer from a legacy row',
        'status' => 'completed',
        'tool_calls' => [
            ['id' => 'a', 'name' => 'WebSearchTool', 'arguments' => []],
            ['id' => 'b', 'name' => 'WebSearchTool', 'arguments' => []],
        ],
        'tool_results' => [
            ['id' => 'a', 'name' => 'WebSearchTool', 'arguments' => [], 'result' => 'r_a'],
            ['id' => 'b', 'name' => 'WebSearchTool', 'arguments' => [], 'result' => 'r_b'],
        ],
        'created_at' => now()->subSecond(),
    ]);

    $store = app(ConversationStore::class);
    $messages = $store->getLatestConversationMessages($conversation->id, 100);

    // Exactly two Message instances: the user and a plain assistant
    // content-only message (no tool_calls, no tool_result block).
    $assistantMessage = $messages->first(fn ($m) => $m instanceof AssistantMessage);
    $toolResultMessage = $messages->first(fn ($m) => $m instanceof ToolResultMessage);

    expect($assistantMessage)->not->toBeNull();
    expect($assistantMessage->content)->toBe('answer from a legacy row');
    expect($assistantMessage->toolCalls->isEmpty())->toBeTrue();
    expect($toolResultMessage)->toBeNull();
});
