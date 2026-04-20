<?php

use App\Ai\Tools\Chat\Calculator;
use Laravel\Ai\Tools\Request;

test('returns error when no expression is provided', function () {
    $result = (string) (new Calculator)->handle(new Request([]));

    expect($result)->toContain('Error: No expression provided');
});

test('evaluates integer arithmetic', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => '2 + 2 * 3',
    ]));

    expect($result)->toBe('2 + 2 * 3 = 8');
});

test('honours operator precedence and parentheses', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => '(2 + 2) * 3',
    ]));

    expect($result)->toBe('(2 + 2) * 3 = 12');
});

test('evaluates floating point division', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => '87.50 * 1.18 / 5',
    ]));

    expect($result)->toContain('87.50 * 1.18 / 5 = 20.65');
});

test('supports math functions like sqrt and round', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => 'sqrt(144) + round(3.7)',
    ]));

    expect($result)->toBe('sqrt(144) + round(3.7) = 16');
});

test('surfaces parse errors without leaking stack traces', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => '2 + * 3',
    ]));

    expect($result)->toStartWith('Error: Could not evaluate "2 + * 3"');
});

test('rejects division by zero cleanly', function () {
    $result = (string) (new Calculator)->handle(new Request([
        'expression' => '1 / 0',
    ]));

    expect($result)->toStartWith('Error: Could not evaluate "1 / 0"');
});
