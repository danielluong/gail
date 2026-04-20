<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;

test('search returns empty array for empty query', function () {
    $this->getJson(route('conversations.search'))
        ->assertOk()
        ->assertJsonCount(0);
});

test('search returns empty array for whitespace-only query', function () {
    $this->getJson(route('conversations.search', ['q' => '   ']))
        ->assertOk()
        ->assertJsonCount(0);
});

test('search finds conversations by title', function () {
    Conversation::factory()->create(['title' => 'Laravel best practices']);
    Conversation::factory()->create(['title' => 'React patterns']);

    $this->getJson(route('conversations.search', ['q' => 'Laravel']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['title' => 'Laravel best practices']);
});

test('search finds conversations by message content', function () {
    $conversation = Conversation::factory()->create(['title' => 'General chat']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Tell me about database migrations',
    ]);

    $this->getJson(route('conversations.search', ['q' => 'migrations']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['title' => 'General chat']);
});

test('search excludes soft-deleted conversations', function () {
    Conversation::factory()->create([
        'title' => 'Deleted conversation',
        'deleted_at' => now(),
    ]);

    $this->getJson(route('conversations.search', ['q' => 'Deleted']))
        ->assertOk()
        ->assertJsonCount(0);
});

test('search deduplicates results matching both title and content', function () {
    $conversation = Conversation::factory()->create(['title' => 'PHP tips']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Here are some PHP tips',
    ]);

    $this->getJson(route('conversations.search', ['q' => 'PHP']))
        ->assertOk()
        ->assertJsonCount(1);
});
