<?php

use App\Ai\Tools\MySQLDatabase\ServerInfoTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * ServerInfoTool is a one-shot metadata probe. It has no user input
 * beyond the connection_token, so the argument-layer surface to test
 * is small — but the tool's contract with the prompt (mention
 * lower_case_table_names so the agent knows about identifier case
 * folding) is worth pinning here.
 */

beforeEach(function () {
    $this->tool = app(ServerInfoTool::class);
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

test('description names the settings the prompt expects the agent to consult', function () {
    $description = (string) $this->tool->description();

    expect($description)
        ->toContain('lower_case_table_names')
        ->toContain('sql_mode')
        ->toContain('time_zone');
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Read MySQL server info');
});
