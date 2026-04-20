<?php

use App\Ai\Database\SqlSafetyValidator;

/*
 * The validator is the single point where an agent-initiated query is
 * either blessed or blocked. Each test below captures one category of
 * attack or mistake we have to keep rejecting, plus the happy-path
 * shapes we must keep accepting. If any of these ever flip, the agent's
 * "strictly read-only" guarantee breaks.
 */

function sqlSafety(): SqlSafetyValidator
{
    static $instance;

    return $instance ??= new SqlSafetyValidator;
}

test('accepts a plain SELECT', function () {
    $result = sqlSafety()->validate('SELECT id, name FROM users WHERE active = 1');

    expect($result->allowed)->toBeTrue()
        ->and($result->firstKeyword)->toBe('SELECT');
});

test('accepts SHOW, DESCRIBE, DESC, and EXPLAIN', function () {
    foreach (['SHOW TABLES', 'DESCRIBE users', 'DESC users', 'EXPLAIN SELECT 1'] as $query) {
        $result = sqlSafety()->validate($query);
        expect($result->allowed)->toBeTrue("{$query} should be allowed");
    }
});

test('rejects an empty query', function () {
    $result = sqlSafety()->validate('   ');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('Empty');
});

test('rejects INSERT', function () {
    $result = sqlSafety()->validate('INSERT INTO users (name) VALUES ("x")');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('read-only');
});

test('rejects UPDATE, DELETE, REPLACE, DROP, ALTER, TRUNCATE, CREATE, GRANT, REVOKE', function (string $query) {
    expect(sqlSafety()->validate($query)->allowed)->toBeFalse();
})->with([
    'UPDATE users SET name = "x"',
    'DELETE FROM users',
    'REPLACE INTO users VALUES (1)',
    'DROP TABLE users',
    'ALTER TABLE users ADD COLUMN x INT',
    'TRUNCATE TABLE users',
    'CREATE TABLE t (id INT)',
    'GRANT ALL ON *.* TO me',
    'REVOKE ALL ON *.* FROM me',
]);

test('rejects multi-statement payloads', function () {
    $result = sqlSafety()->validate('SELECT 1; DROP TABLE users');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('Multiple statements');
});

test('accepts a single statement with a trailing semicolon', function () {
    $result = sqlSafety()->validate('SELECT 1;');

    expect($result->allowed)->toBeTrue();
});

test('rejects a write statement hidden behind a line comment', function () {
    $result = sqlSafety()->validate("-- SELECT 1\nDROP TABLE users");

    expect($result->allowed)->toBeFalse();
});

test('rejects a write statement hidden behind a block comment', function () {
    $result = sqlSafety()->validate('/* nice */ DROP TABLE users');

    expect($result->allowed)->toBeFalse();
});

test('rejects versioned MySQL comments outright', function () {
    $result = sqlSafety()->validate('/*!50001 DROP TABLE users */');

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('Versioned');
});

test('does NOT mistake forbidden keywords inside string literals for a write', function () {
    $result = sqlSafety()->validate("SELECT id FROM users WHERE note = 'please do not drop table'");

    expect($result->allowed)->toBeTrue();
});

test('rejects SELECT ... INTO OUTFILE', function () {
    $result = sqlSafety()->validate("SELECT * FROM users INTO OUTFILE '/tmp/dump.csv'");

    expect($result->allowed)->toBeFalse()
        ->and($result->reason)->toContain('OUTFILE');
});

test('rejects SELECT ... FOR UPDATE', function () {
    // `UPDATE` alone is already on the forbidden keyword list, so this
    // rejects for that reason rather than the dedicated FOR UPDATE
    // guard — the important property is that it's blocked at all.
    expect(sqlSafety()->validate('SELECT * FROM users FOR UPDATE')->allowed)->toBeFalse();
});

test('rejects SELECT ... LOCK IN SHARE MODE (which the dedicated guard catches)', function () {
    $result = sqlSafety()->validate('SELECT * FROM users LOCK IN SHARE MODE');

    expect($result->allowed)->toBeFalse();
});

test('rejects WITH / CTE fronting an INSERT', function () {
    $result = sqlSafety()->validate('WITH rows AS (SELECT 1) INSERT INTO log SELECT * FROM rows');

    expect($result->allowed)->toBeFalse();
});

test('rejects transaction control statements', function (string $query) {
    expect(sqlSafety()->validate($query)->allowed)->toBeFalse();
})->with([
    'BEGIN',
    'COMMIT',
    'ROLLBACK',
    'START TRANSACTION',
    'SET autocommit = 0',
    'USE otherdb',
    'FLUSH PRIVILEGES',
    'KILL 1',
]);

test('accepts a query with a safe LIMIT', function () {
    $result = sqlSafety()->validate('SELECT * FROM users ORDER BY id DESC LIMIT 10');

    expect($result->allowed)->toBeTrue();
});

test('reason message includes the offending keyword for write attempts', function () {
    $result = sqlSafety()->validate('INSERT INTO users (id) VALUES (1)');

    expect($result->reason)->toContain('INSERT');
});
