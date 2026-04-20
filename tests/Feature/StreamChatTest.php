<?php

use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\TitlerAgent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Project;
use Laravel\Ai\Contracts\ConversationStore;

beforeEach(function () {
    ChatAgent::fake([
        'Hello from Gail.',
    ]);
    TitlerAgent::fake(['Friendly greeting']);
});

test('stream returns a text/event-stream response with [DONE] terminator', function () {
    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi there',
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toStartWith('text/event-stream');

    $body = $response->streamedContent();

    expect($body)->toContain("data: [DONE]\n\n");
});

test('stream yields text delta events frames as SSE', function () {
    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi there',
    ]);

    $body = $response->streamedContent();

    // Each event is framed as "data: {json}\n\n". Every non-[DONE] frame
    // should be valid JSON.
    $frames = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->filter()
        ->values();

    expect($frames->count())->toBeGreaterThan(1);

    foreach ($frames as $frame) {
        expect(str_starts_with($frame, 'data: '))->toBeTrue();

        $payload = substr($frame, 6);

        if ($payload === '[DONE]') {
            continue;
        }

        // Fake agent emits laravel/ai Streaming\Events\* instances whose
        // (string) cast is a JSON-encoded `data: ...` envelope.
        expect(json_decode($payload, true))->not->toBeNull(
            "Frame was not valid JSON: {$payload}"
        );
    }
});

test('stream persists a conversation and emits the conversation id event', function () {
    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi there',
    ]);

    $body = $response->streamedContent();

    // Find the conversation event frame.
    $conversationFrame = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->first(function (string $frame) {
            if (! str_starts_with($frame, 'data: ')) {
                return false;
            }

            $payload = substr($frame, 6);

            if ($payload === '[DONE]') {
                return false;
            }

            $decoded = json_decode($payload, true);

            return is_array($decoded) && ($decoded['type'] ?? null) === 'conversation';
        });

    expect($conversationFrame)->not->toBeNull();

    $decoded = json_decode(substr($conversationFrame, 6), true);

    expect($decoded)
        ->toHaveKey('conversation_id')
        ->and($decoded['conversation_id'])->toBeString();

    expect(Conversation::where('id', $decoded['conversation_id'])->exists())->toBeTrue();
});

test('stream tags a newly created conversation with the requested project id', function () {
    $project = Project::factory()->create();

    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi there',
        'project_id' => $project->id,
    ]);

    $body = $response->streamedContent();

    $conversationFrame = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->first(function (string $frame) {
            $payload = substr($frame, 6);
            $decoded = json_decode($payload, true);

            return is_array($decoded) && ($decoded['type'] ?? null) === 'conversation';
        });

    expect($conversationFrame)->not->toBeNull();

    $decoded = json_decode(substr($conversationFrame, 6), true);

    expect(
        Conversation::where('id', $decoded['conversation_id'])
            ->where('project_id', $project->id)
            ->exists()
    )->toBeTrue();
});

test('stream emits a message_usage event with tokens and cost for the persisted assistant reply', function () {
    config()->set('pricing.gpt-4o', ['input' => 2.5, 'output' => 10.0]);

    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi there',
        'model' => 'gpt-4o',
    ]);

    $body = $response->streamedContent();

    $frames = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->filter(fn ($frame) => str_starts_with($frame, 'data: '))
        ->map(fn ($frame) => substr($frame, 6))
        ->filter(fn ($payload) => $payload !== '[DONE]')
        ->map(fn ($payload) => json_decode($payload, true))
        ->filter(fn ($decoded) => is_array($decoded))
        ->values();

    $usageFrame = $frames->firstWhere('type', 'message_usage');

    expect($usageFrame)->not->toBeNull()
        ->and($usageFrame)->toHaveKeys(['message_id', 'user_message_id', 'usage', 'cost']);

    $latestAssistant = ConversationMessage::query()
        ->where('role', 'assistant')
        ->orderByDesc('created_at')
        ->first();

    $latestUser = ConversationMessage::query()
        ->where('role', 'user')
        ->orderByDesc('created_at')
        ->first();

    expect($usageFrame['message_id'])->toBe($latestAssistant->id);
    expect($usageFrame['user_message_id'])->toBe($latestUser->id);
});

test('stream triggers auto-title on the first assistant turn', function () {
    TitlerAgent::fake(['Pizza toppings in Brooklyn']);

    $response = $this->post(route('chat.stream'), [
        'message' => "What's a good pizza place in Brooklyn?",
    ]);

    $body = $response->streamedContent();

    $conversationFrame = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->first(function (string $frame) {
            $payload = substr($frame, 6);
            $decoded = json_decode($payload, true);

            return is_array($decoded) && ($decoded['type'] ?? null) === 'conversation';
        });

    $decoded = json_decode(substr($conversationFrame, 6), true);

    expect(
        Conversation::where('id', $decoded['conversation_id'])
            ->where('title', 'Pizza toppings in Brooklyn')
            ->exists()
    )->toBeTrue();
});

test('stream does not re-title on later turns or regenerates', function () {
    $conversation = Conversation::factory()->create(['title' => 'Kept Title']);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'earlier question',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'earlier answer',
        'created_at' => now()->subMinutes(1),
    ]);

    TitlerAgent::fake(['Should Not Apply']);

    $this->post(route('chat.stream'), [
        'message' => 'follow-up question',
        'conversation_id' => $conversation->id,
    ]);

    expect($conversation->fresh()->title)->toBe('Kept Title');
});

test('canceled continuation leaves user and assistant rows as canceled for UI replay', function () {
    $conversation = Conversation::factory()->create();

    ChatAgent::fake(function () {
        throw new RuntimeException('client aborted');
    });

    $response = $this->post(route('chat.stream'), [
        'message' => 'this will be canceled',
        'conversation_id' => $conversation->id,
    ]);

    $response->streamedContent();

    $rows = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get(['role', 'status', 'content']);

    expect($rows)->toHaveCount(2);
    expect($rows[0]->role)->toBe('user');
    expect($rows[0]->status)->toBe('canceled');
    expect($rows[0]->content)->toBe('this will be canceled');
    expect($rows[1]->role)->toBe('assistant');
    expect($rows[1]->status)->toBe('canceled');
});

test('successful continuation promotes pending rows to completed without duplicating', function () {
    $conversation = Conversation::factory()->create();

    $response = $this->post(route('chat.stream'), [
        'message' => 'follow up',
        'conversation_id' => $conversation->id,
    ]);

    $response->streamedContent();

    $rows = ConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get(['role', 'status', 'content']);

    expect($rows)->toHaveCount(2);
    expect($rows[0]->role)->toBe('user');
    expect($rows[0]->status)->toBe('completed');
    expect($rows[0]->content)->toBe('follow up');
    expect($rows[1]->role)->toBe('assistant');
    expect($rows[1]->status)->toBe('completed');
    expect($rows[1]->content)->toBe('Hello from Gail.');
});

test('conversation store filters canceled and pending rows out of prompt context', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'status' => 'completed',
        'content' => 'kept user message',
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'status' => 'completed',
        'content' => 'kept assistant reply',
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'status' => 'canceled',
        'content' => 'ghost user message',
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'status' => 'pending',
        'content' => 'in-flight reply',
    ]);

    $messages = app(ConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 100);

    $rendered = $messages->map(fn ($m) => $m->content ?? '')->implode('|');

    expect($rendered)->toContain('kept user message');
    expect($rendered)->toContain('kept assistant reply');
    expect($rendered)->not->toContain('ghost user message');
    expect($rendered)->not->toContain('in-flight reply');
});

test('stream emits an error event and still terminates with [DONE] on failure', function () {
    // Re-fake the agent with a closure that throws, so streamText raises
    // inside the SSE generator. The controller should catch, frame the
    // error as an event, and still yield [DONE].
    ChatAgent::fake(function () {
        throw new RuntimeException('boom');
    });

    $response = $this->post(route('chat.stream'), [
        'message' => 'Hi',
    ]);

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)->toContain("data: [DONE]\n\n");

    $hasError = collect(explode("\n\n", $body))
        ->map(fn ($frame) => trim($frame))
        ->contains(function (string $frame) {
            if (! str_starts_with($frame, 'data: ')) {
                return false;
            }

            $payload = substr($frame, 6);

            if ($payload === '[DONE]') {
                return false;
            }

            $decoded = json_decode($payload, true);

            return is_array($decoded) && ($decoded['type'] ?? null) === 'error';
        });

    expect($hasError)->toBeTrue();
});
