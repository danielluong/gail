<?php

use App\Actions\Conversations\ExportConversation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

test('markdown export renders one section per message with role labels', function () {
    $conversation = Conversation::factory()->create(['title' => 'Pizza Talk']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'What should I order?',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Pepperoni.',
        'created_at' => now()->subMinute(),
    ]);

    $response = (new ExportConversation)->execute($conversation);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->headers->get('Content-Type'))->toBe('text/markdown');
    expect($response->headers->get('Content-Disposition'))->toContain('pizza-talk.md');

    $body = $response->getContent();
    expect($body)->toContain('# Pizza Talk')
        ->toContain('## User')
        ->toContain('What should I order?')
        ->toContain('## Assistant')
        ->toContain('Pepperoni.');
});

test('json export returns a JSON response with messages and a download header', function () {
    $conversation = Conversation::factory()->create(['title' => 'JSON Export Test']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hi',
    ]);

    $response = (new ExportConversation)->execute($conversation, 'json');

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->headers->get('Content-Disposition'))->toContain('json-export-test.json');

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toHaveKeys(['title', 'exported_at', 'messages']);
    expect($payload['title'])->toBe('JSON Export Test');
    expect($payload['messages'])->toHaveCount(1);
    expect($payload['messages'][0]['content'])->toBe('Hi');
});
