<?php

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('analytics renders the analytics page with an empty 30-day range by default', function () {
    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics')
            ->where('range_days', 30)
            ->has('totals')
            ->has('messages_per_day', 30)
            ->has('tokens_per_day', 30)
            ->has('tool_usage')
            ->has('model_breakdown')
            ->where('totals.messages', 0)
            ->where('totals.user_messages', 0)
            ->where('totals.assistant_messages', 0)
            ->where('totals.total_tokens', 0)
            ->where('totals.prompt_tokens', 0)
            ->where('totals.completion_tokens', 0)
            ->where('totals.tool_calls', 0)
        );
});

test('analytics messages_per_day buckets have the expected shape', function () {
    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('messages_per_day.0', fn ($day) => $day
                ->has('date')
                ->has('count')
                ->etc()
            )
        );
});

test('analytics tokens_per_day buckets have the expected shape', function () {
    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tokens_per_day.0', fn ($day) => $day
                ->has('date')
                ->has('prompt')
                ->has('completion')
                ->etc()
            )
        );
});

test('analytics totals count user and assistant messages separately', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'created_at' => now()->subDays(1),
    ]);

    ConversationMessage::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'created_at' => now()->subDays(1),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.messages', 5)
            ->where('totals.user_messages', 2)
            ->where('totals.assistant_messages', 3)
        );
});

test('analytics totals sum prompt and completion tokens from usage json', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        'created_at' => now()->subDays(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'usage' => ['prompt_tokens' => 25, 'completion_tokens' => 75],
        'created_at' => now()->subDays(1),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.prompt_tokens', 125)
            ->where('totals.completion_tokens', 125)
            ->where('totals.total_tokens', 250)
        );
});

test('analytics tool usage aggregates calls by tool name and sorts descending', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [
            ['name' => 'FileReader'],
            ['name' => 'ShellCommand'],
            ['name' => 'FileReader'],
        ],
        'created_at' => now()->subDays(1),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [
            ['name' => 'FileReader'],
        ],
        'created_at' => now()->subDays(1),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.tool_calls', 4)
            ->where('tool_usage.0', ['name' => 'FileReader', 'count' => 3])
            ->where('tool_usage.1', ['name' => 'ShellCommand', 'count' => 1])
        );
});

test('analytics model breakdown groups assistant messages by model', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'llama3.1:8b', 'provider' => 'ollama'],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        'created_at' => now()->subDays(1),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'llama3.1:8b', 'provider' => 'ollama'],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        'created_at' => now()->subDays(1),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'meta' => ['model' => 'qwen3-vl:8b', 'provider' => 'ollama'],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2],
        'created_at' => now()->subDays(1),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('model_breakdown.0', [
                'model' => 'llama3.1:8b',
                'provider' => 'ollama',
                'messages' => 2,
                'tokens' => 40,
            ])
            ->where('model_breakdown.1', [
                'model' => 'qwen3-vl:8b',
                'provider' => 'ollama',
                'messages' => 1,
                'tokens' => 3,
            ])
        );
});

test('analytics excludes messages older than the 30 day window', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'created_at' => now()->subDays(45),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'created_at' => now()->subDays(5),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.messages', 1)
        );
});

test('analytics tolerates malformed or empty json fields without failing', function () {
    $conversation = Conversation::factory()->create();

    // Insert raw strings via the query builder to bypass the model's
    // array casts, matching how laravel/ai writes message rows directly
    // and how malformed data could realistically reach the DB.
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversation->id,
        'agent' => 'ChatAgent',
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => '',
        'tool_results' => '[]',
        'usage' => 'not-valid-json',
        'meta' => '',
        'created_at' => now()->subDays(1),
        'updated_at' => now()->subDays(1),
    ]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.total_tokens', 0)
            ->where('totals.tool_calls', 0)
        );
});
