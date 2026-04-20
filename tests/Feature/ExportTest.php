<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;

test('export returns markdown by default', function () {
    $conversation = Conversation::factory()->create(['title' => 'Test Chat']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello there',
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Hi! How can I help?',
    ]);

    $response = $this->get(route('conversations.export', $conversation->id));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    $response->assertHeader('Content-Disposition', 'attachment; filename="test-chat.md"');
    $this->assertStringContainsString('# Test Chat', $response->getContent());
    $this->assertStringContainsString('Hello there', $response->getContent());
    $this->assertStringContainsString('Hi! How can I help?', $response->getContent());
});

test('export returns json when format=json', function () {
    $conversation = Conversation::factory()->create(['title' => 'JSON Test']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Test message',
    ]);

    $response = $this->getJson(route('conversations.export', [
        'conversation' => $conversation->id,
        'format' => 'json',
    ]));

    $response->assertOk();
    $response->assertJsonFragment(['title' => 'JSON Test']);
    $response->assertJsonPath('messages.0.role', 'user');
    $response->assertJsonPath('messages.0.content', 'Test message');
});

test('export returns 404 for non-existent conversation', function () {
    $this->get(route('conversations.export', 'non-existent'))
        ->assertNotFound();
});

test('export returns 404 for soft-deleted conversation', function () {
    $conversation = Conversation::factory()->create(['deleted_at' => now()]);

    $this->get(route('conversations.export', $conversation->id))
        ->assertNotFound();
});
