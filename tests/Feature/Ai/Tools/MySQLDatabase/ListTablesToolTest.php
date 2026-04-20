<?php

use App\Ai\Tools\MySQLDatabase\ListTablesTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * ListTablesTool takes no user input other than the connection_token,
 * so the argument-layer surface to test is small — but it's the
 * agent's primary orientation tool, so the refusal paths and the
 * user-facing description are worth pinning.
 */

beforeEach(function () {
    $this->tool = app(ListTablesTool::class);
});

test('refuses without a connection_token', function () {
    $result = $this->tool->handle(new Request([]));

    expect($result)->toContain('connection_token is required');
});

test('refuses with an unknown connection_token', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'definitely-not-a-real-token',
    ]));

    expect($result)->toContain('expired');
});

test('exposes a schema with only the connection_token', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
    ]);
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Listed MySQL tables');
});
