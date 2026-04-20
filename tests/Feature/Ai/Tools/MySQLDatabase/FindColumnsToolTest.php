<?php

use App\Ai\Tools\MySQLDatabase\FindColumnsTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * FindColumnsTool binds the pattern as a PDO parameter, so the user
 * can safely include SQL LIKE wildcards. These tests cover the
 * argument-layer rejections that fire before any connection attempt.
 */

beforeEach(function () {
    $this->tool = app(FindColumnsTool::class);
});

test('refuses without a pattern', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
    ]));

    expect($result)->toContain('column name or LIKE pattern is required');
});

test('refuses an overlong pattern before opening a connection', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
        'pattern' => str_repeat('a', 200),
    ]));

    expect($result)->toContain('too long');
});

test('refuses without a connection_token once the pattern passes', function () {
    $result = $this->tool->handle(new Request([
        'pattern' => '%email%',
    ]));

    expect($result)->toContain('connection_token is required');
});

test('exposes a schema describing connection_token and pattern', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
        'pattern',
    ]);
});

test('description documents the LIKE wildcard syntax the model can reach for', function () {
    $description = (string) $this->tool->description();

    expect($description)
        ->toContain('LIKE')
        ->toContain('%');
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Searched MySQL columns');
});
