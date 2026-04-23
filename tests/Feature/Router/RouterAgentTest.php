<?php

use App\Ai\Agents\Router\ChatAgent as RouterChatAgent;
use App\Ai\Agents\Router\QuestionAnswerAgent;
use App\Ai\Agents\Router\RouterAgent;
use App\Ai\Agents\Router\TaskAgent;
use App\Enums\InputCategory;

test('defaults to the chat specialist prompt when no category has been set', function () {
    $instructions = (string) (new RouterAgent)->instructions();

    expect($instructions)->toContain(RouterChatAgent::PROMPT);
});

test('composes the question specialist prompt when category=question', function () {
    $agent = (new RouterAgent)->withCategory(InputCategory::Question);

    expect((string) $agent->instructions())
        ->toContain(QuestionAnswerAgent::PROMPT)
        ->toContain('Category: question');
});

test('composes the task specialist prompt when category=task', function () {
    $agent = (new RouterAgent)->withCategory(InputCategory::Task);

    expect((string) $agent->instructions())
        ->toContain(TaskAgent::PROMPT)
        ->toContain('Category: task');
});

test('composes the casual-chat specialist prompt when category=chat', function () {
    $agent = (new RouterAgent)->withCategory(InputCategory::Chat);

    expect((string) $agent->instructions())
        ->toContain(RouterChatAgent::PROMPT)
        ->toContain('Category: chat');
});

test('surfaces the classifier confidence in the prompt header', function () {
    $agent = (new RouterAgent)
        ->withCategory(InputCategory::Task)
        ->withConfidence(0.87);

    expect((string) $agent->instructions())->toContain('Confidence: 0.87');
});

test('surfaces a classifier warning message in the prompt header', function () {
    $agent = (new RouterAgent)
        ->withCategory(InputCategory::Chat)
        ->withClassifierWarning('Classifier returned non-JSON output; defaulting to chat.');

    expect((string) $agent->instructions())
        ->toContain('Warning:')
        ->toContain('non-JSON output');
});

test('tools() is always empty — router specialists are tool-free', function () {
    $agent = new RouterAgent;

    expect($agent->tools())->toBe([]);
});
