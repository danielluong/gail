<?php

use App\Ai\Database\DatabaseConnectionStore;
use App\Ai\Database\MySqlConnectionFactory;
use App\Ai\Database\SqlAmbiguityLinter;
use App\Ai\Database\SqlSafetyValidator;
use App\Ai\Services\MySQL\QueryExecutionService;
use App\Ai\Tools\MySQLDatabase\ExportQueryCsvTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

/*
 * ExportQueryCsvTool routes every DB interaction through the shared
 * QueryExecutionService kernel. These tests cover the tool-specific
 * slice: refusal paths for unsafe or unsupported queries, schema
 * surface, and the happy path that writes a CSV file and hands back
 * a download link. The happy path substitutes a SQLite PDO via a
 * QueryExecutionService subclass bound in the container, so we don't
 * need a live MySQL for CI.
 */

/**
 * Bind a QueryExecutionService that returns the given PDO regardless
 * of the token. Uses the real validator/linter/store/factory so the
 * safety layer still executes end-to-end.
 */
function stubbedKernel(PDO $pdo): QueryExecutionService
{
    return new class($pdo) extends QueryExecutionService
    {
        public function __construct(private readonly PDO $pdo)
        {
            parent::__construct(
                app(DatabaseConnectionStore::class),
                app(MySqlConnectionFactory::class),
                app(SqlSafetyValidator::class),
                app(SqlAmbiguityLinter::class),
            );
        }

        public function getConnection(?string $token): PDO
        {
            return $this->pdo;
        }
    };
}

beforeEach(function () {
    $this->tool = app(ExportQueryCsvTool::class);
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

test('refuses an empty query', function () {
    $result = $this->tool->handle(new Request([
        'connection_token' => 'anything',
        'query' => '',
    ]));

    expect($result)->toContain('`query` parameter is required');
});

test('refuses a write statement even with a valid token', function () {
    $token = app(DatabaseConnectionStore::class)->store([
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

test('refuses a multi-statement payload', function () {
    $token = app(DatabaseConnectionStore::class)->store(['database' => 'x'], engine: 'mysql');

    $result = $this->tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SELECT 1; DROP TABLE users',
    ]));

    expect($result)->toContain('Multiple statements');
});

test('refuses SHOW/DESCRIBE/EXPLAIN because export is SELECT-only', function () {
    $token = app(DatabaseConnectionStore::class)->store(['database' => 'x'], engine: 'mysql');

    $result = $this->tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SHOW TABLES',
    ]));

    expect($result)->toContain('only supports SELECT');
});

test('writes a CSV to the public disk and returns a markdown download link', function () {
    Storage::fake('public');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE fruits (id INTEGER PRIMARY KEY, name TEXT, qty INTEGER)');
    $pdo->exec("INSERT INTO fruits (id, name, qty) VALUES (1, 'apple', 5), (2, 'pear', 2)");

    app()->instance(QueryExecutionService::class, stubbedKernel($pdo));
    $tool = app(ExportQueryCsvTool::class);

    $token = app(DatabaseConnectionStore::class)->store(['database' => 'x'], engine: 'mysql');

    $result = (string) $tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SELECT id, name, qty FROM fruits ORDER BY id',
        'filename' => 'fruit sales!',
    ]));

    expect($result)->toStartWith('[Download fruit-sales.csv](')
        ->toContain('2 rows, 3 columns')
        ->not->toContain('truncated');

    $files = Storage::disk('public')->files('ai-exports');
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('-fruit-sales.csv');

    $csv = Storage::disk('public')->get($files[0]);
    expect($csv)
        ->toContain("id,name,qty\n")
        ->toContain("1,apple,5\n")
        ->toContain("2,pear,2\n");
});

test('flags truncation when the row count hits the limit', function () {
    Storage::fake('public');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE t (id INTEGER)');
    $pdo->exec('INSERT INTO t VALUES (1), (2), (3), (4), (5)');

    app()->instance(QueryExecutionService::class, stubbedKernel($pdo));
    $tool = app(ExportQueryCsvTool::class);

    $token = app(DatabaseConnectionStore::class)->store(['database' => 'x'], engine: 'mysql');

    $result = (string) $tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SELECT id FROM t',
        'limit' => 2,
    ]));

    expect($result)->toContain('2 rows')
        ->toContain('truncated at 2-row cap');
});

test('respects an existing LIMIT and does not append another', function () {
    Storage::fake('public');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE t (id INTEGER)');
    $pdo->exec('INSERT INTO t VALUES (1), (2), (3)');

    app()->instance(QueryExecutionService::class, stubbedKernel($pdo));
    $tool = app(ExportQueryCsvTool::class);

    $token = app(DatabaseConnectionStore::class)->store(['database' => 'x'], engine: 'mysql');

    $result = (string) $tool->handle(new Request([
        'connection_token' => $token,
        'query' => 'SELECT id FROM t LIMIT 2',
    ]));

    // The tool returns a markdown download link, not JSON. Two
    // signals prove LIMIT was respected: the summary reports the
    // user's 2-row cap (not the default 10,000), and the query ran
    // without a syntax error — if applyLimit had appended a second
    // LIMIT clause, SQLite would have rejected `LIMIT 2 LIMIT 10000`
    // and we'd have seen an Error string instead of a download link.
    expect($result)
        ->toStartWith('[Download ')
        ->toContain('2 rows')
        ->not->toContain('truncated');

    $files = Storage::disk('public')->files('ai-exports');
    $csv = Storage::disk('public')->get($files[0]);
    expect(substr_count($csv, "\n"))->toBe(3); // header + 2 data rows
});

test('schema exposes connection_token, query, filename, and limit', function () {
    $schema = $this->tool->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'connection_token',
        'query',
        'filename',
        'limit',
    ]);
});

test('description makes the export use case obvious to the model', function () {
    $description = (string) $this->tool->description();

    expect($description)
        ->toContain('CSV')
        ->toContain('download')
        ->toContain('SELECT');
});

test('label is user-facing and past-tense to match the UI toolLabels convention', function () {
    expect($this->tool->label())->toBe('Exported a CSV');
});
