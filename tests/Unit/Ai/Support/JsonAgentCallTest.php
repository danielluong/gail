<?php

use App\Ai\Support\JsonAgentCall;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function jsonAgentCallResponse(string $text): AgentResponse
{
    return new AgentResponse('test-invocation', $text, new Usage, new Meta);
}

test('returns the parsed array and an empty warning on a valid JSON reply', function () {
    [$parsed, $warning] = JsonAgentCall::tryDecode(
        logTag: 'tests.valid',
        humanLabel: 'Classifier',
        call: fn () => jsonAgentCallResponse('{"category":"chat","confidence":0.9}'),
    );

    expect($parsed)->toBe(['category' => 'chat', 'confidence' => 0.9]);
    expect($warning)->toBe('');
});

test('logs and returns a failure warning when the closure throws', function () {
    Log::shouldReceive('channel')->with('ai')->once()->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($tag, $context) => $tag === 'tests.throw'
            && ($context['query'] ?? null) === 'hi'
            && ($context['error'] ?? null) === 'upstream exploded'
        );

    [$parsed, $warning] = JsonAgentCall::tryDecode(
        logTag: 'tests.throw',
        humanLabel: 'Classifier',
        call: fn () => throw new RuntimeException('upstream exploded'),
        logContext: ['query' => 'hi'],
    );

    expect($parsed)->toBeNull();
    expect($warning)->toBe('Classifier call failed: upstream exploded');
});

test('returns a decode warning when the reply is not JSON', function () {
    [$parsed, $warning] = JsonAgentCall::tryDecode(
        logTag: 'tests.garbage',
        humanLabel: 'Critic',
        call: fn () => jsonAgentCallResponse('sorry, I cannot comply'),
    );

    expect($parsed)->toBeNull();
    expect($warning)->toBe('Critic returned non-JSON output; defaulting.');
});
