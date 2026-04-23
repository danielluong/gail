<?php

use App\Actions\Router\RunRouterExample;
use App\Ai\Agents\Router\ChatAgent as RouterChatAgent;
use App\Ai\Agents\Router\ClassifierAgent;
use App\Ai\Agents\Router\QuestionAnswerAgent;
use App\Ai\Agents\Router\TaskAgent;

test('routes a high-confidence question to the QuestionAnswerAgent', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.9]),
    ]);
    QuestionAnswerAgent::fake(['Recursion is a function that calls itself.']);

    $result = app(RunRouterExample::class)->execute('Explain what recursion is');

    expect($result['category'])->toBe('question');
    expect($result['confidence'])->toBe(0.9);
    expect($result['agent'])->toBe('QuestionAnswerAgent');
    expect($result['response'])->toBe('Recursion is a function that calls itself.');
    expect($result['warnings'])->toBe([]);
});

test('routes a task to the TaskAgent', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'task', 'confidence' => 0.85]),
    ]);
    TaskAgent::fake(['Here is the summary: ...']);

    $result = app(RunRouterExample::class)->execute('Summarize this paragraph for me');

    expect($result['category'])->toBe('task');
    expect($result['agent'])->toBe('TaskAgent');
    expect($result['response'])->toBe('Here is the summary: ...');
});

test('routes casual chat to the ChatAgent', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'chat', 'confidence' => 0.8]),
    ]);
    RouterChatAgent::fake(['Hey! How can I help?']);

    $result = app(RunRouterExample::class)->execute('hi there');

    expect($result['category'])->toBe('chat');
    expect($result['agent'])->toBe('ChatAgent');
    expect($result['response'])->toBe('Hey! How can I help?');
});

test('falls back to ChatAgent when classifier confidence is below threshold', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.3]),
    ]);
    RouterChatAgent::fake(['Low-confidence fallback reply.']);

    $result = app(RunRouterExample::class)->execute('???');

    expect($result['category'])->toBe('chat');
    expect($result['agent'])->toBe('ChatAgent');
    expect($result['confidence'])->toBe(0.3);
    expect($result['response'])->toBe('Low-confidence fallback reply.');
});

test('falls back to ChatAgent with a warning when classifier returns non-JSON', function () {
    ClassifierAgent::fake(['sorry, I can not classify this input']);
    RouterChatAgent::fake(['Sure, let us chat.']);

    $result = app(RunRouterExample::class)->execute('anything');

    expect($result['category'])->toBe('chat');
    expect($result['agent'])->toBe('ChatAgent');
    expect($result['warnings'])->not->toBeEmpty();
    expect($result['warnings'][0])->toContain('non-JSON');
});

test('falls back to ChatAgent with a warning when classifier returns an unknown category', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'greetings', 'confidence' => 0.9]),
    ]);
    RouterChatAgent::fake(['Hello!']);

    $result = app(RunRouterExample::class)->execute('hi');

    expect($result['category'])->toBe('chat');
    expect($result['warnings'])->toContain('Classifier returned an unknown category; defaulting to chat.');
});

test('clamps a confidence value outside [0, 1] and records a warning', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'task', 'confidence' => 1.5]),
    ]);
    TaskAgent::fake(['done']);

    $result = app(RunRouterExample::class)->execute('do something');

    expect($result['confidence'])->toBe(1.0);
    expect($result['warnings'])->toContain('Classifier returned a confidence outside [0, 1]; clamping.');
});

test('returns an empty-input error payload without calling any agent', function () {
    ClassifierAgent::assertNeverPrompted();

    $result = app(RunRouterExample::class)->execute('   ');

    expect($result['response'])->toBe('');
    expect($result['agent'])->toBe('ChatAgent');
    expect($result['warnings'])->toContain('Empty input; nothing to classify.');
});
