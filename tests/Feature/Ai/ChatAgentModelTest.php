<?php

use App\Ai\Agents\ChatAgent;

test('chat agent resolves model from the active provider default', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.default_model', 'gpt-4o');

    expect((new ChatAgent)->model())->toBe('gpt-4o');
});

test('chat agent returns null when the active provider has no default model', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.default_model', null);

    expect((new ChatAgent)->model())->toBeNull();
});

test('shipped configuration resolves openai default model', function () {
    expect(config('ai.default'))->toBe('openai');
    expect(config('ai.providers.openai.default_model'))->toBe('gpt-4o');
    expect((new ChatAgent)->model())->toBe('gpt-4o');
});
