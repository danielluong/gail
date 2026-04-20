<?php

use App\Actions\Analytics\ComputeUsageMetrics;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Cache;

test('execute returns zero totals for an empty dataset', function () {
    $metrics = (new ComputeUsageMetrics)->execute(30);

    expect($metrics['range_days'])->toBe(30);
    expect($metrics['totals']['messages'])->toBe(0);
    expect($metrics['totals']['total_tokens'])->toBe(0);
    expect($metrics['tool_usage'])->toBe([]);
    expect($metrics['model_breakdown'])->toBe([]);
});

test('totals sum prompt and completion tokens from the usage cast', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 70],
        'created_at' => now()->subDays(1),
    ]);
    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 15],
        'created_at' => now()->subDays(2),
    ]);

    Cache::flush();
    $metrics = (new ComputeUsageMetrics)->execute(30);

    expect($metrics['totals']['prompt_tokens'])->toBe(35);
    expect($metrics['totals']['completion_tokens'])->toBe(85);
    expect($metrics['totals']['total_tokens'])->toBe(120);
});

test('tool usage counts tool calls grouped by name', function () {
    $conversation = Conversation::factory()->create();

    ConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'tool_calls' => [
            ['name' => 'WebSearch'],
            ['name' => 'Calculator'],
            ['name' => 'WebSearch'],
        ],
        'created_at' => now()->subDays(1),
    ]);

    Cache::flush();
    $metrics = (new ComputeUsageMetrics)->execute(30);

    expect($metrics['totals']['tool_calls'])->toBe(3);

    $usage = collect($metrics['tool_usage'])->keyBy('name');
    expect($usage['WebSearch']['count'])->toBe(2);
    expect($usage['Calculator']['count'])->toBe(1);
});

test('execute caches results for the given range', function () {
    Cache::flush();

    $metrics = (new ComputeUsageMetrics)->execute(30);

    $cached = Cache::get('gail:usage-metrics:30');
    expect($cached)->toBe($metrics);
});

test('results are scoped to the requested day window', function () {
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

    Cache::flush();
    $metrics = (new ComputeUsageMetrics)->execute(30);

    expect($metrics['totals']['messages'])->toBe(1);
});
