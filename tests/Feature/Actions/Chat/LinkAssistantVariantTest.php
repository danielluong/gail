<?php

use App\Actions\Chat\LinkAssistantVariant;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

test('links new assistant to the prior original and drops the duplicate user message', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->subMinutes(10),
    ]);
    $original = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'first reply',
        'created_at' => now()->subMinutes(9),
    ]);

    $pivot = Carbon::now();

    $duplicateUser = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->addSecond(),
    ]);
    $newAssistant = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'regen reply',
        'created_at' => now()->addSeconds(2),
    ]);

    app(LinkAssistantVariant::class)->execute($conversation->id, $pivot);

    expect(ConversationMessage::find($duplicateUser->id))->toBeNull();
    expect(ConversationMessage::find($newAssistant->id)->variant_of)->toBe($original->id);
    expect(ConversationMessage::find($original->id)->variant_of)->toBeNull();
});

test('is a no-op when there is no prior original to link to', function () {
    $conversation = Conversation::factory()->create();

    $pivot = Carbon::now();

    $newUser = ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->addSecond(),
    ]);
    $newAssistant = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'first reply',
        'created_at' => now()->addSeconds(2),
    ]);

    app(LinkAssistantVariant::class)->execute($conversation->id, $pivot);

    expect(ConversationMessage::find($newUser->id))->not->toBeNull();
    expect(ConversationMessage::find($newAssistant->id)->variant_of)->toBeNull();
});

test('links back to the canonical original even when prior regens exist', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->subMinutes(20),
    ]);
    $original = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'v1',
        'created_at' => now()->subMinutes(19),
    ]);
    ConversationMessage::factory()->variantOf($original)->create([
        'content' => 'v2',
        'created_at' => now()->subMinutes(10),
    ]);

    $pivot = Carbon::now();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'created_at' => now()->addSecond(),
    ]);
    $newAssistant = ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'v3',
        'created_at' => now()->addSeconds(2),
    ]);

    app(LinkAssistantVariant::class)->execute($conversation->id, $pivot);

    expect(ConversationMessage::find($newAssistant->id)->variant_of)->toBe($original->id);
});
