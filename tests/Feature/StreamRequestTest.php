<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;

test('stream request validates temperature is between 0 and 2', function () {
    $this->postJson(route('chat.stream'), [
        'message' => 'hello',
        'temperature' => 3,
    ])->assertUnprocessable();

    $this->postJson(route('chat.stream'), [
        'message' => 'hello',
        'temperature' => -0.1,
    ])->assertUnprocessable();

    $this->postJson(route('chat.stream'), [
        'message' => 'hello',
        'temperature' => 'hot',
    ])->assertUnprocessable();
});

test('stream request requires a message', function () {
    $this->postJson(route('chat.stream'), [])->assertUnprocessable();
});

test('stream request rejects an unknown edit_message_id', function () {
    $this->postJson(route('chat.stream'), [
        'message' => 'hello',
        'edit_message_id' => 'does-not-exist',
    ])->assertUnprocessable();

    $this->postJson(route('chat.stream'), [
        'message' => 'hello',
        'edit_message_id' => 123,
    ])->assertUnprocessable();
});

test('edit_message_id truncates the target message and everything after it', function () {
    $conversation = Conversation::factory()->create();

    $m0 = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Message 0',
        'created_at' => now()->subMinutes(5),
    ]);
    $m1 = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Message 1',
        'created_at' => now()->subMinutes(4),
    ]);
    $m2 = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Message 2',
        'created_at' => now()->subMinutes(3),
    ]);
    $m3 = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Message 3',
        'created_at' => now()->subMinutes(2),
    ]);

    // The streaming endpoint runs truncation synchronously before starting
    // the generator. Calling it may fail when the stream connects to the AI
    // provider, but truncation should have already run.
    try {
        $this->postJson(route('chat.stream'), [
            'message' => 'New message',
            'conversation_id' => $conversation->id,
            'edit_message_id' => $m2->id,
        ]);
    } catch (Throwable) {
        // Ignore streaming errors; we only care about the side effect.
    }

    // $m2 is the pivot: it and $m3 are gone, $m0 and $m1 remain.
    expect(ConversationMessage::where('id', $m0->id)->exists())->toBeTrue();
    expect(ConversationMessage::where('id', $m1->id)->exists())->toBeTrue();
    expect(ConversationMessage::where('id', $m2->id)->exists())->toBeFalse();
    expect(ConversationMessage::where('id', $m3->id)->exists())->toBeFalse();
});
