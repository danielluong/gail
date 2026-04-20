<?php

use App\Support\Formatters\ToolCallFormatter;

test('returns empty array when no calls provided', function () {
    expect((new ToolCallFormatter)->format(null, null))->toBe([]);
    expect((new ToolCallFormatter)->format([], []))->toBe([]);
});

test('pairs tool calls with their results by id', function () {
    $formatter = new ToolCallFormatter;

    $calls = [
        ['id' => 'a', 'name' => 'Weather', 'arguments' => ['city' => 'Paris']],
        ['id' => 'b', 'name' => 'Calculator', 'arguments' => ['expr' => '1+1']],
    ];
    $results = [
        ['id' => 'b', 'result' => '2'],
        ['id' => 'a', 'result' => '18C sunny'],
    ];

    expect($formatter->format($calls, $results))->toBe([
        ['tool_id' => 'a', 'tool_name' => 'Weather', 'arguments' => ['city' => 'Paris'], 'result' => '18C sunny'],
        ['tool_id' => 'b', 'tool_name' => 'Calculator', 'arguments' => ['expr' => '1+1'], 'result' => '2'],
    ]);
});

test('tolerates missing fields without crashing', function () {
    $formatter = new ToolCallFormatter;

    $calls = [['id' => 'a']];

    expect($formatter->format($calls, null))->toBe([
        ['tool_id' => 'a', 'tool_name' => '', 'arguments' => [], 'result' => null],
    ]);
});
