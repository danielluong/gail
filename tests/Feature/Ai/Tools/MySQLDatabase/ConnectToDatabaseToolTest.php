<?php

use App\Ai\Tools\MySQLDatabase\ConnectToDatabaseTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

/*
 * Verifies the ConnectToDatabaseTool fails fast on obvious credential
 * problems before ever reaching the driver. End-to-end MySQL
 * connection tests live wherever the integration test environment
 * supplies a real server — this suite only covers input validation so
 * it can run on any machine.
 */

beforeEach(function () {
    $this->tool = app(ConnectToDatabaseTool::class);
});

test('rejects empty host', function () {
    $result = $this->tool->handle(new Request([
        'host' => '',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
        'database' => 'app',
    ]));

    expect($result)->toContain('host is required');
});

test('rejects an out-of-range port', function () {
    $result = $this->tool->handle(new Request([
        'host' => '127.0.0.1',
        'port' => 70000,
        'username' => 'root',
        'password' => '',
        'database' => 'app',
    ]));

    expect($result)->toContain('port must be between');
});

test('rejects empty username', function () {
    $result = $this->tool->handle(new Request([
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => '',
        'password' => 'x',
        'database' => 'app',
    ]));

    expect($result)->toContain('username is required');
});

test('rejects empty database', function () {
    $result = $this->tool->handle(new Request([
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
        'database' => '',
    ]));

    expect($result)->toContain('database is required');
});

test('schema declares every required credential field', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'host',
        'port',
        'username',
        'password',
        'database',
    ]);
});

test('description instructs the model to call this first and reuse the token', function () {
    $description = (string) $this->tool->description();

    expect($description)
        ->toContain('connection_token')
        ->toContain('before any other database tool');
});

test('label is user-facing past-tense', function () {
    expect($this->tool->label())->toBe('Connected to MySQL');
});
