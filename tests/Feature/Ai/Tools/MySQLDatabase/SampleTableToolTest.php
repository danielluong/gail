<?php

use App\Ai\Tools\MySQLDatabase\SampleTableTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * SampleTableTool interpolates the table name into a backtick-quoted
 * identifier, so identifier validation has to fire before any PDO
 * connection attempt. Pin those boundaries plus the cell-elision
 * helper behaviour.
 */

beforeEach(function () {
    $this->tool = app(SampleTableTool::class);
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

test('exposes a schema describing connection_token, table, and optional limit', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
        'table',
        'limit',
    ]);
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Sampled MySQL table');
});

test('elideLargeCells replaces oversized string values with a byte-count placeholder', function () {
    $rows = [
        [
            'id' => 1,
            'name' => 'Alice',
            'bio' => str_repeat('x', 500),
        ],
        [
            'id' => 2,
            'name' => 'Bob',
            'bio' => 'short bio',
        ],
    ];

    $elide = (new ReflectionClass(SampleTableTool::class))->getMethod('elideLargeCells');
    $elide->setAccessible(true);

    $result = $elide->invoke(app(SampleTableTool::class), $rows);

    expect($result[0]['bio'])->toBe('<elided 500 bytes>');
    expect($result[0]['name'])->toBe('Alice');
    expect($result[1]['bio'])->toBe('short bio');
    expect($result[1]['id'])->toBe(2);
});
