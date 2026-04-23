<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Project;

test('index returns conversations and projects', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => $project->id]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat')
            ->has('projects', 1)
            ->has('conversations', 1)
        );
});

test('index shares the configured ai provider so the UI can toggle provider-specific controls', function () {
    config()->set('ai.default', 'ollama');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('aiProvider', 'ollama'));

    config()->set('ai.default', 'openai');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('aiProvider', 'openai'));
});

test('index excludes soft-deleted conversations and projects', function () {
    Project::factory()->create(['deleted_at' => now()]);
    Conversation::factory()->create(['deleted_at' => now()]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('projects', 0)
            ->has('conversations', 0)
        );
});

test('messages returns conversation messages as json', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello',
    ]);

    $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['role' => 'user', 'content' => 'Hello'])
        ->assertJsonStructure([['id', 'role', 'content', 'attachments', 'toolCalls', 'model', 'created_at']]);
});

test('messages expose the model stored in meta for assistant responses', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Reply',
        'meta' => ['provider' => 'openai', 'model' => 'gpt-4o'],
    ]);

    $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk()
        ->assertJsonPath('0.model', 'gpt-4o');
});

test('messages return a null model when meta has no model', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hi',
        'meta' => [],
    ]);

    $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk()
        ->assertJsonPath('0.model', null);
});

test('messages collapse variants into the top-level slot with history', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->subMinutes(5),
    ]);
    $original = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'original answer',
        'meta' => ['provider' => 'openai', 'model' => 'gpt-4o'],
        'created_at' => now()->subMinutes(4),
    ]);
    ConversationMessage::factory()
        ->variantOf($original)
        ->create([
            'content' => 'regen 1',
            'meta' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
            'created_at' => now()->subMinutes(3),
        ]);
    ConversationMessage::factory()
        ->variantOf($original)
        ->create([
            'content' => 'regen 2 (latest)',
            'meta' => ['provider' => 'ollama', 'model' => 'gemma4:e4b'],
            'created_at' => now()->subMinute(),
        ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk()
        ->assertJsonCount(2);

    expect($response->json('0.role'))->toBe('user');
    expect($response->json('0'))->not->toHaveKey('variants');

    expect($response->json('1.id'))->toBe($original->id);
    expect($response->json('1.content'))->toBe('regen 2 (latest)');
    expect($response->json('1.model'))->toBe('gemma4:e4b');
    expect($response->json('1.variants'))->toHaveCount(2);
    expect($response->json('1.variants.0.content'))->toBe('original answer');
    expect($response->json('1.variants.1.content'))->toBe('regen 1');
});

test('messages collapse uses the latest variant phases, not the original', function () {
    /*
     * Regression: regenerating a research turn with a non-research
     * agent (e.g. flipping the dropdown to Default Agent) left the
     * original research turn's phase strip visible on the top-level
     * message after refresh. The collapse code was carrying
     * `content` and the other per-variant fields forward from the
     * latest variant but still inheriting `phases` from the base
     * (original) row.
     */
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'q',
        'created_at' => now()->subMinutes(5),
    ]);

    $original = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'research answer',
        'meta' => [
            'model' => 'gpt-4o',
            'phases' => [
                ['key' => 'researcher', 'label' => 'Researching', 'status' => 'complete'],
                ['key' => 'editor', 'label' => 'Editing', 'status' => 'complete'],
                ['key' => 'critic', 'label' => 'Reviewing', 'status' => 'complete'],
            ],
        ],
        'created_at' => now()->subMinutes(4),
    ]);

    ConversationMessage::factory()
        ->variantOf($original)
        ->create([
            'content' => 'chat agent answer',
            'meta' => ['model' => 'gpt-4o'], // no phases — this variant came from the plain ChatAgent
            'created_at' => now()->subMinute(),
        ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk();

    expect($response->json('1.content'))->toBe('chat agent answer');
    // The latest variant had no phases, so the top-level slot must
    // not inherit the research phases from the original.
    expect($response->json('1.phases'))->toBe([]);
    // The original's phases are still carried on the variant itself.
    expect($response->json('1.variants.0.phases'))->toHaveCount(3);
});

test('messages omit variants key for assistant messages without regenerations', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'solo answer',
    ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk();

    expect($response->json('0'))->not->toHaveKey('variants');
});

test('messages hydrates persisted tool calls into the streaming shape', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Sure, looking that up now.',
        'tool_calls' => [
            ['id' => 'call_abc', 'name' => 'WebSearch', 'arguments' => ['query' => 'pizza']],
            ['id' => 'call_def', 'name' => 'CurrentDateTime', 'arguments' => []],
        ],
        'tool_results' => [
            '12' => ['id' => 'call_abc', 'name' => 'WebSearch', 'result' => 'Search results: …'],
            '34' => ['id' => 'call_def', 'name' => 'CurrentDateTime', 'result' => '2026-04-11 12:00:00'],
        ],
    ]);

    $toolCalls = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk()
        ->json('0.toolCalls');

    expect($toolCalls)->toHaveCount(2)
        ->and($toolCalls[0])->toBe([
            'tool_id' => 'call_abc',
            'tool_name' => 'WebSearch',
            'arguments' => ['query' => 'pizza'],
            'result' => 'Search results: …',
        ])
        ->and($toolCalls[1])->toBe([
            'tool_id' => 'call_def',
            'tool_name' => 'CurrentDateTime',
            'arguments' => [],
            'result' => '2026-04-11 12:00:00',
        ]);
});

test('messages returns an empty toolCalls list for messages without tool usage', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'No tools here.',
    ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk();

    expect($response->json('0.toolCalls'))->toBe([]);
});

test('messages hydrates persisted image attachments into previewable urls', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Look at this',
        'attachments' => [
            [
                'type' => 'local-image',
                'name' => 'photo.jpg',
                'path' => storage_path('app/private/uploads/abc123.jpg'),
                'mime' => 'image/jpeg',
            ],
        ],
    ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk();

    $attachments = $response->json('0.attachments');

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]['name'])->toBe('photo.jpg')
        ->and($attachments[0]['type'])->toBe('image/jpeg')
        ->and($attachments[0]['url'])->toContain('/uploads/abc123.jpg');
});

test('messages returns an empty attachments list for messages without files', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Just text',
        'attachments' => [],
    ]);

    $response = $this->getJson(route('conversations.messages', $conversation->id))
        ->assertOk();

    expect($response->json('0.attachments'))->toBe([]);
});

test('messages returns 404 for non-existent conversation', function () {
    $this->getJson(route('conversations.messages', 'non-existent'))
        ->assertNotFound();
});

test('messages returns 404 for soft-deleted conversation', function () {
    $conversation = Conversation::factory()->create(['deleted_at' => now()]);

    $this->getJson(route('conversations.messages', $conversation->id))
        ->assertNotFound();
});

test('update renames a conversation', function () {
    $conversation = Conversation::factory()->create(['title' => 'Old Title']);

    $this->patchJson(route('conversations.update', $conversation->id), ['title' => 'New Title'])
        ->assertNoContent();

    expect($conversation->fresh()->title)->toBe('New Title');
});

test('update moves a conversation to a project', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create();

    $this->patchJson(route('conversations.update', $conversation->id), ['project_id' => $project->id])
        ->assertNoContent();

    expect($conversation->fresh()->project_id)->toBe($project->id);
});

test('update validates input', function () {
    $conversation = Conversation::factory()->create();

    $this->patchJson(route('conversations.update', $conversation->id), ['title' => ''])
        ->assertUnprocessable();

    $this->patchJson(route('conversations.update', $conversation->id), ['project_id' => 99999])
        ->assertUnprocessable();
});

test('update returns 404 for soft-deleted conversation', function () {
    $conversation = Conversation::factory()->create(['deleted_at' => now()]);

    $this->patchJson(route('conversations.update', $conversation->id), ['title' => 'New'])
        ->assertNotFound();
});

test('destroy soft-deletes a conversation', function () {
    $conversation = Conversation::factory()->create();

    $this->deleteJson(route('conversations.destroy', $conversation->id))
        ->assertNoContent();

    expect($conversation->fresh()->deleted_at)->not->toBeNull();
});

test('destroy returns 404 for already deleted conversation', function () {
    $conversation = Conversation::factory()->create(['deleted_at' => now()]);

    $this->deleteJson(route('conversations.destroy', $conversation->id))
        ->assertNotFound();
});

test('update pins a conversation', function () {
    $conversation = Conversation::factory()->create();

    expect($conversation->is_pinned)->toBeFalse();

    $this->patchJson(route('conversations.update', $conversation->id), ['is_pinned' => true])
        ->assertNoContent();

    expect($conversation->fresh()->is_pinned)->toBeTrue();
});

test('update unpins a conversation', function () {
    $conversation = Conversation::factory()->pinned()->create();

    expect($conversation->is_pinned)->toBeTrue();

    $this->patchJson(route('conversations.update', $conversation->id), ['is_pinned' => false])
        ->assertNoContent();

    expect($conversation->fresh()->is_pinned)->toBeFalse();
});

test('update validates is_pinned is boolean', function () {
    $conversation = Conversation::factory()->create();

    $this->patchJson(route('conversations.update', $conversation->id), ['is_pinned' => 'not-a-bool'])
        ->assertUnprocessable();
});

test('branch creates a new conversation with messages up to the branch point', function () {
    $conversation = Conversation::factory()->create(['title' => 'Original']);

    $first = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'First message',
        'created_at' => now()->subMinutes(3),
    ]);
    $second = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'First reply',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Second message',
        'created_at' => now()->subMinute(),
    ]);
    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Second reply',
        'created_at' => now(),
    ]);

    $response = $this->postJson(route('conversations.branch', $conversation->id), [
        'message_id' => $second->id,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['id', 'title', 'project_id', 'parent_id', 'is_pinned', 'updated_at'])
        ->assertJsonFragment([
            'title' => 'Original',
            'parent_id' => $conversation->id,
        ]);

    $branchId = $response->json('id');

    expect($branchId)->not->toBe($conversation->id);

    $branch = Conversation::findOrFail($branchId);

    expect($branch->messages()->count())->toBe(2);

    $copiedContents = $branch->messages()
        ->orderBy('created_at')
        ->pluck('content')
        ->all();

    expect($copiedContents)->toBe(['First message', 'First reply']);

    expect($conversation->fresh()->messages()->count())->toBe(4);
    expect(ConversationMessage::where('id', $first->id)->exists())->toBeTrue();
});

test('branch copies project assignment', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => $project->id]);
    $message = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
    ]);

    $response = $this->postJson(route('conversations.branch', $conversation->id), [
        'message_id' => $message->id,
    ]);

    $response->assertCreated()->assertJsonFragment(['project_id' => $project->id]);
});

test('branch validates that the message exists', function () {
    $conversation = Conversation::factory()->create();

    $this->postJson(route('conversations.branch', $conversation->id), [
        'message_id' => 'non-existent-id',
    ])->assertUnprocessable();
});

test('branch rejects a message from another conversation', function () {
    $conversation = Conversation::factory()->create();
    $other = Conversation::factory()->create();
    $foreign = ConversationMessage::factory()->create([
        'conversation_id' => $other->id,
    ]);

    $this->postJson(route('conversations.branch', $conversation->id), [
        'message_id' => $foreign->id,
    ])->assertNotFound();
});

test('branch returns 404 for soft-deleted conversation', function () {
    $conversation = Conversation::factory()->create(['deleted_at' => now()]);

    $this->postJson(route('conversations.branch', $conversation->id), [
        'message_id' => 'anything',
    ])->assertNotFound();
});

test('index includes parent_id for branched conversations', function () {
    $parent = Conversation::factory()->create();
    Conversation::factory()->create(['parent_id' => $parent->id]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat')
            ->has('conversations', 2)
            ->where('conversations.0.parent_id', $parent->id)
        );
});

test('index orders pinned conversations first', function () {
    $older = Conversation::factory()->create([
        'title' => 'Older unpinned',
        'updated_at' => now()->subDays(5),
    ]);

    $newer = Conversation::factory()->create([
        'title' => 'Newer unpinned',
        'updated_at' => now()->subDays(1),
    ]);

    $pinned = Conversation::factory()->pinned()->create([
        'title' => 'Pinned old',
        'updated_at' => now()->subDays(10),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat')
            ->where('conversations.0.id', $pinned->id)
            ->where('conversations.1.id', $newer->id)
            ->where('conversations.2.id', $older->id)
        );
});
