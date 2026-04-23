<?php

use App\Ai\Agents\ChatAgent;
use App\Ai\Workflow\Pipelines\SingleAgentPipeline;

test('run() invokes the wrapped agent and returns the response text', function () {
    ChatAgent::fake(['Hi there!']);

    $pipeline = new SingleAgentPipeline(ChatAgent::make());

    $result = $pipeline->run(['query' => 'hello']);

    expect($result['query'])->toBe('hello');
    expect($result['response'])->toBe('Hi there!');
    expect($result['warnings'])->toBe([]);
});

test('run() preserves existing warnings from the input dict', function () {
    ChatAgent::fake(['ok']);

    $pipeline = new SingleAgentPipeline(ChatAgent::make());

    $result = $pipeline->run([
        'query' => 'hi',
        'warnings' => ['upstream warning'],
    ]);

    expect($result['warnings'])->toBe(['upstream warning']);
});

test('stream() yields SSE-framed events and returns the accumulated response', function () {
    ChatAgent::fake(['Hello from Gail.']);

    $pipeline = new SingleAgentPipeline(ChatAgent::make());

    $generator = $pipeline->stream(['query' => 'hi']);

    $frames = [];

    foreach ($generator as $frame) {
        $frames[] = $frame;
    }

    $result = $generator->getReturn();

    expect($frames)->not->toBeEmpty();

    foreach ($frames as $frame) {
        expect($frame)->toStartWith('data: ');
        expect($frame)->toEndWith("\n\n");
    }

    expect($result['query'])->toBe('hi');
    expect($result['response'])->toBe('Hello from Gail.');
});

test('stream() preserves existing warnings from the input dict', function () {
    ChatAgent::fake(['ok']);

    $pipeline = new SingleAgentPipeline(ChatAgent::make());

    $generator = $pipeline->stream([
        'query' => 'hi',
        'warnings' => ['upstream warning'],
    ]);

    iterator_to_array($generator);

    $result = $generator->getReturn();

    expect($result['warnings'])->toBe(['upstream warning']);
});
