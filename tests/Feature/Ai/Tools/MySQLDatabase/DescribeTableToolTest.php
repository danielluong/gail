<?php

use App\Ai\Tools\MySQLDatabase\DescribeTableTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * DescribeTableTool interpolates the caller-supplied table name into
 * a parameterised INFORMATION_SCHEMA query; the strict identifier
 * regex is the only thing between the model and a raw value landing
 * in the bound parameter. Pin the argument-layer refusals here.
 */

beforeEach(function () {
    $this->tool = app(DescribeTableTool::class);
});

test('refuses without a table name', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
    ]));

    expect($result)->toContain('table name is required');
});

test('refuses an identifier that is not a bare table name', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
        'table' => 'users; DROP TABLE users',
    ]));

    expect($result)->toContain('letters, digits, and underscores');
});

test('refuses without a connection_token once the identifier passes', function () {
    $result = $this->tool->handle(new Request([
        'table' => 'users',
    ]));

    expect($result)->toContain('connection_token is required');
});

test('exposes a schema describing connection_token and table', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
        'table',
    ]);
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Described MySQL table');
});
