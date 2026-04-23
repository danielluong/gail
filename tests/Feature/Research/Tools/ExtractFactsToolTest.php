<?php

use App\Ai\Agents\Research\LlmCallerAgent;
use App\Ai\Tools\Research\ExtractFactsTool;
use Laravel\Ai\Tools\Request;

test('returns JSON error when text is empty', function () {
    $result = (string) (new ExtractFactsTool)->handle(new Request([
        'text' => '',
        'schema' => 'x',
    ]));

    expect(json_decode($result, true))->toHaveKey('error');
});

test('returns JSON error when schema is empty', function () {
    $result = (string) (new ExtractFactsTool)->handle(new Request([
        'text' => 'something',
        'schema' => '',
    ]));

    expect(json_decode($result, true))->toHaveKey('error');
});

test('parses well-formed JSON response', function () {
    LlmCallerAgent::fake(['{"pros":["a","b"],"cons":["c"]}']);

    $result = (string) (new ExtractFactsTool)->handle(new Request([
        'text' => 'source text',
        'schema' => 'Extract pros and cons',
    ]));

    expect(json_decode($result, true))->toMatchArray([
        'pros' => ['a', 'b'],
        'cons' => ['c'],
    ]);
});

test('strips ```json code fences before parsing', function () {
    LlmCallerAgent::fake([
        "```json\n{\"key\":\"value\"}\n```",
    ]);

    $decoded = (new ExtractFactsTool)->extract('t', 'schema');

    expect($decoded)->toMatchArray(['key' => 'value']);
});

test('falls back to object-span recovery when the model adds preamble', function () {
    LlmCallerAgent::fake([
        'Sure, here is the JSON: {"a":1} — hope this helps.',
    ]);

    $decoded = (new ExtractFactsTool)->extract('t', 's');

    expect($decoded)->toMatchArray(['a' => 1]);
});

test('returns an error payload when the model response is not JSON at all', function () {
    LlmCallerAgent::fake(['this is not json, just prose']);

    $decoded = (new ExtractFactsTool)->extract('t', 's');

    expect($decoded)
        ->toHaveKey('error')
        ->toHaveKey('raw');
});
