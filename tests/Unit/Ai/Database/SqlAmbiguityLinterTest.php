<?php

use App\Ai\Database\SqlAmbiguityLinter;

/*
 * The linter catches one specific wrong-result pattern the safety
 * validator intentionally ignores: unqualified identifiers in GROUP BY
 * on a multi-table query. The real incident that motivated this was a
 * `GROUP BY u.id, name` query that silently grouped by `projects.name`
 * (because the joined table also had a column named `name`) instead of
 * the SELECT alias, producing one row per project name instead of per
 * user. Every test below pins a rule we have to keep honoring.
 */

function linter(): SqlAmbiguityLinter
{
    static $instance;

    return $instance ??= new SqlAmbiguityLinter;
}

test('single-table GROUP BY with a bare identifier is allowed', function () {
    expect(linter()->lint('SELECT status, COUNT(*) FROM users GROUP BY status'))->toBeNull();
});

test('single-table GROUP BY with an alias is allowed', function () {
    expect(linter()->lint('SELECT MONTH(created_at) AS m FROM users GROUP BY m'))->toBeNull();
});

test('query without GROUP BY is allowed even with a JOIN', function () {
    expect(linter()->lint('SELECT u.id, p.id FROM users u JOIN projects p ON u.id = p.user_id'))->toBeNull();
});

test('rejects a multi-table GROUP BY with a bare identifier', function () {
    $result = linter()->lint(
        'SELECT u.id, COUNT(p.id) FROM users u LEFT JOIN projects p ON u.id = p.user_id GROUP BY u.id, name'
    );

    expect($result)
        ->toBeString()
        ->toContain('`name`')
        ->toContain('ambiguous')
        ->toContain('u.name');
});

test('accepts qualified identifiers in GROUP BY on a multi-table query', function () {
    $clean = 'SELECT u.id, COUNT(p.id) FROM users u LEFT JOIN projects p ON u.id = p.user_id GROUP BY u.id, u.first_name, u.last_name';

    expect(linter()->lint($clean))->toBeNull();
});

test('accepts expressions in GROUP BY on a multi-table query', function () {
    $clean = "SELECT u.id, COUNT(p.id) FROM users u LEFT JOIN projects p ON u.id = p.user_id GROUP BY u.id, CONCAT(u.first_name, ' ', u.last_name)";

    expect(linter()->lint($clean))->toBeNull();
});

test('accepts numeric ordinals in GROUP BY even on a multi-table query', function () {
    // GROUP BY 1, 2 is compact but unambiguous — the position maps
    // directly to the SELECT list.
    $clean = 'SELECT u.id, u.name FROM users u JOIN projects p ON u.id = p.user_id GROUP BY 1, 2';

    expect(linter()->lint($clean))->toBeNull();
});

test('rejects old-style implicit join (comma FROM) with a bare GROUP BY identifier', function () {
    $result = linter()->lint(
        'SELECT u.id, COUNT(*) FROM users u, projects p WHERE u.id = p.user_id GROUP BY u.id, name'
    );

    expect($result)->toBeString()->toContain('ambiguous');
});

test('is case insensitive about the GROUP BY keyword and identifiers', function () {
    $result = linter()->lint(
        'select u.id, count(p.id) from users u join projects p on u.id = p.user_id group by u.id, name'
    );

    expect($result)->toBeString()->toContain('`name`');
});

test('flags bare identifiers even when ASC / DESC modifiers are present', function () {
    // GROUP BY can accept ASC/DESC in MySQL; strip the modifier before
    // deciding whether the item is bare.
    $result = linter()->lint(
        'SELECT u.id FROM users u JOIN projects p ON u.id = p.user_id GROUP BY u.id, name DESC'
    );

    expect($result)->toBeString()->toContain('`name`');
});

test('does not mistake identifiers inside string literals for bare GROUP BY items', function () {
    $clean = "SELECT u.id FROM users u JOIN projects p ON u.id = p.user_id WHERE u.label = 'GROUP BY name' GROUP BY u.id";

    expect(linter()->lint($clean))->toBeNull();
});

test('does not mistake identifiers inside block comments for bare GROUP BY items', function () {
    $clean = '/* GROUP BY name */ SELECT u.id FROM users u JOIN projects p ON u.id = p.user_id GROUP BY u.id';

    expect(linter()->lint($clean))->toBeNull();
});

test('handles GROUP BY followed by HAVING without false positives', function () {
    $result = linter()->lint(
        'SELECT u.id, COUNT(p.id) AS c FROM users u LEFT JOIN projects p ON u.id = p.user_id GROUP BY u.id, name HAVING c > 1'
    );

    expect($result)->toBeString()->toContain('`name`');
});

test('does not consume identifiers from ORDER BY or LIMIT when scanning GROUP BY', function () {
    // The regex must stop at ORDER BY so `name` below (in ORDER BY) is
    // not mistakenly pulled into the GROUP BY check.
    $clean = 'SELECT u.id, u.first_name FROM users u JOIN projects p ON u.id = p.user_id GROUP BY u.id, u.first_name ORDER BY name LIMIT 10';

    // The lint only covers GROUP BY — ORDER BY ambiguity is out of
    // scope for v1, so this specific case should not raise an error
    // about `name` coming from ORDER BY.
    expect(linter()->lint($clean))->toBeNull();
});

test('error message mentions all bare identifiers when multiple are present', function () {
    $result = linter()->lint(
        'SELECT u.id FROM users u JOIN projects p ON u.id = p.user_id GROUP BY name, status'
    );

    expect($result)
        ->toBeString()
        ->toContain('`name`')
        ->toContain('`status`');
});
