<?php

use App\Actions\Chat\GenerateConversationTitle;
use App\Ai\Agents\TitlerAgent;
use App\Models\Conversation;
use App\Models\ConversationMessage;

beforeEach(function () {
    TitlerAgent::fake(['Pizza toppings in Brooklyn']);
});

test('writes a summarized title after the first assistant reply', function () {
    $conversation = Conversation::factory()->create(['title' => 'Whats a good pizza place']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => "What's a good pizza place in Brooklyn?",
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Di Fara in Midwood is the classic answer...',
        'created_at' => now()->subMinutes(1),
    ]);

    (new GenerateConversationTitle)->execute($conversation->id);

    expect($conversation->fresh()->title)->toBe('Pizza toppings in Brooklyn');
});

test('does nothing when the conversation has no assistant reply yet', function () {
    $conversation = Conversation::factory()->create(['title' => 'Original']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello?',
    ]);

    (new GenerateConversationTitle)->execute($conversation->id);

    expect($conversation->fresh()->title)->toBe('Original');
});

test('silently no-ops for an unknown conversation id', function () {
    (new GenerateConversationTitle)->execute('missing-id');

    expect(true)->toBeTrue();
});

test('strips wrapping quotes and trailing punctuation from model output', function () {
    TitlerAgent::fake(['"Title: Pizza toppings in Brooklyn."']);

    $conversation = Conversation::factory()->create();
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Q',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'A',
        'created_at' => now()->subMinutes(1),
    ]);

    (new GenerateConversationTitle)->execute($conversation->id);

    expect($conversation->fresh()->title)->toBe('Pizza toppings in Brooklyn');
});
