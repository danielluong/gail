<?php

use App\Ai\Database\DatabaseConnectionStore;
use App\Ai\Tools\MySQLDatabase\RunSelectQueryTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * RunSelectQueryTool is the most safety-critical tool in the agent's
 * kit — it's the one the model will reach for most often. These tests
 * verify the tool refuses unsafe input at the token, validator, and
 * argument layers before any PDO connection is attempted.
 */

beforeEach(function () {
    $this->tool = app(RunSelectQueryTool::class);
});

test('refuses to run without a connection_token', function () {
    $result = $this->tool->handle(new Request(['query' => 'SELECT 1']));

    expect($result)->toContain('connection_token is required');
});

test('refuses to run with an unknown connection_token', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'definitely-not-a-real-token',
        'query' => 'SELECT 1',
    ]));

    expect($result)->toContain('expired');
});

test('refuses to run a write statement even with a valid token', function () {
    /** @var DatabaseConnectionStore $store */
    $store = app(DatabaseConnectionStore::class);
    $token = $store->store([
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
        'database' => 'test',
    ], engine: 'mysql');

    $result = $this->tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'DELETE FROM users',
    ]));

    expect($result)->toContain('read-only');
});

test('refuses an ambiguous multi-table GROUP BY before opening a connection', function () {
    // The ambiguity linter runs during preflight, so a bad query never
    // hits PDO. The token here is bogus on purpose — if the linter
    // did not fire first, we would see a connection error instead.
    $result = $this->tool->handle(new Request([
        'connection_token' => 'bogus-token-never-resolved',
        'query' => 'SELECT u.id, COUNT(p.id) FROM users u LEFT JOIN projects p ON u.id = p.user_id GROUP BY u.id, name',
    ]));

    expect($result)
        ->toContain('Unqualified identifier')
        ->toContain('`name`')
        ->toContain('GROUP BY');
});

test('refuses a multi-statement payload', function () {
    /** @var DatabaseConnectionStore $store */
    $store = app(DatabaseConnectionStore::class);
    $token = $store->store(['database' => 'x'], engine: 'mysql');

    $result = $this->tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SELECT 1; DROP TABLE users',
    ]));

    expect($result)->toContain('Multiple statements');
});

test('refuses an empty query', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
        'query' => '',
    ]));

    expect($result)->toContain('`query` parameter is required');
});

test('exposes a schema describing connection_token, query, and optional limit', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
        'query',
        'limit',
    ]);
});

test('description calls out the safety layer in terms the model can cite to the user', function () {
    $description = (string) $this->tool->description();

    expect($description)
        ->toContain('read-only')
        ->toContain('SELECT')
        ->toContain('LIMIT');
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Ran a MySQL query');
});
