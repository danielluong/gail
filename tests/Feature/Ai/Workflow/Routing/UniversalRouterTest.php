<?php

use App\Ai\Workflow\Routing\UniversalRouter;
use App\Enums\InputCategory;
use Illuminate\Support\Facades\Log;

function universalRouter(): UniversalRouter
{
    return new UniversalRouter;
}

// ---------------------------------------------------------------------------
// route(): Router interface — classifier dict → orchestrator path key
// ---------------------------------------------------------------------------

test('routes a high-confidence question to the research path', function () {
    expect(universalRouter()->route(['category' => 'question', 'confidence' => 0.9]))
        ->toBe('research');
});

test('routes a high-confidence task to the content path', function () {
    expect(universalRouter()->route(['category' => 'task', 'confidence' => 0.9]))
        ->toBe('content');
});

test('routes high-confidence chat to the chat path', function () {
    expect(universalRouter()->route(['category' => 'chat', 'confidence' => 0.9]))
        ->toBe('chat');
});

test('falls back to chat for low confidence regardless of classifier category', function () {
    expect(universalRouter()->route(['category' => 'question', 'confidence' => 0.3]))
        ->toBe('chat');
    expect(universalRouter()->route(['category' => 'task', 'confidence' => 0.59]))
        ->toBe('chat');
});

test('keeps the classified category at exactly the threshold', function () {
    expect(universalRouter()->route(['category' => 'question', 'confidence' => 0.6]))
        ->toBe('research');
});

test('unknown category string falls back to chat', function () {
    expect(universalRouter()->route(['category' => 'greetings', 'confidence' => 0.9]))
        ->toBe('chat');
});

test('missing confidence treats it as zero and falls back to chat', function () {
    expect(universalRouter()->route(['category' => 'question']))
        ->toBe('chat');
});

// ---------------------------------------------------------------------------
// routeCategory(): enum-to-enum variant — used by StreamRouterResponse /
// RunRouterExample for the confidence-floor + chat-fallback policy.
// ---------------------------------------------------------------------------

test('routeCategory preserves each enum when confidence is above the threshold', function () {
    $router = universalRouter();

    expect($router->routeCategory(InputCategory::Question, 0.9))->toBe(InputCategory::Question);
    expect($router->routeCategory(InputCategory::Task, 0.9))->toBe(InputCategory::Task);
    expect($router->routeCategory(InputCategory::Chat, 0.9))->toBe(InputCategory::Chat);
});

test('routeCategory falls back to chat when confidence is below the threshold', function () {
    $router = universalRouter();

    expect($router->routeCategory(InputCategory::Question, 0.3))->toBe(InputCategory::Chat);
    expect($router->routeCategory(InputCategory::Task, 0.59))->toBe(InputCategory::Chat);
});

test('routeCategory keeps the classified category at exactly the threshold', function () {
    expect(UniversalRouter::CONFIDENCE_THRESHOLD)->toBe(0.6);
    expect(universalRouter()->routeCategory(InputCategory::Question, 0.6))
        ->toBe(InputCategory::Question);
});

test('routeCategory logs the fallback to the ai channel with the original category + confidence', function () {
    $captured = null;
    Log::shouldReceive('channel')
        ->with('ai')
        ->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->with('router.low_confidence_fallback', Mockery::on(function ($context) use (&$captured) {
            $captured = $context;

            return true;
        }));

    universalRouter()->routeCategory(InputCategory::Task, 0.42);

    expect($captured)->toMatchArray([
        'original_category' => 'task',
        'confidence' => 0.42,
        'threshold' => 0.6,
    ]);
});

test('routeCategory does not log when confidence is above threshold', function () {
    Log::shouldReceive('channel')->never();

    universalRouter()->routeCategory(InputCategory::Question, 0.8);
});
