<?php

use App\Actions\Chat\TruncateMessagesFromMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;

test('truncates the given message and every later message', function () {
    $conversation = Conversation::factory()->create();

    $first = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'first',
        'created_at' => now()->subMinutes(3),
    ]);
    $second = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'second',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'third',
        'created_at' => now()->subMinutes(1),
    ]);

    $deleted = (new TruncateMessagesFromMessage)->execute($conversation->id, $second->id);

    expect($deleted)->toBe(2);

    $remaining = $conversation->messages()
        ->orderBy('created_at')
        ->pluck('content')
        ->all();

    expect($remaining)->toBe(['first'])
        ->and(ConversationMessage::where('id', $first->id)->exists())->toBeTrue();
});

test('returns zero when the message id does not belong to the conversation', function () {
    $conversation = Conversation::factory()->create();
    $other = Conversation::factory()->create();

    ConversationMessage::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
    ]);
    $stranger = ConversationMessage::factory()->create([
        'conversation_id' => $other->id,
    ]);

    $deleted = (new TruncateMessagesFromMessage)->execute($conversation->id, $stranger->id);

    expect($deleted)->toBe(0);
    expect($conversation->messages()->count())->toBe(2);
});

test('returns zero for a non-existent message id', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
    ]);

    $deleted = (new TruncateMessagesFromMessage)->execute($conversation->id, 'missing-message-id');

    expect($deleted)->toBe(0);
    expect($conversation->messages()->count())->toBe(2);
});

test('truncating from the first message deletes every message', function () {
    $conversation = Conversation::factory()->create();

    $first = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'created_at' => now()->subMinutes(3),
    ]);
    ConversationMessage::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'created_at' => now()->subMinutes(1),
    ]);

    $deleted = (new TruncateMessagesFromMessage)->execute($conversation->id, $first->id);

    expect($deleted)->toBe(3);
    expect($conversation->messages()->count())->toBe(0);
});
