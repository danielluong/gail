<?php

use App\Actions\Analytics\AnalyticsJsonSqlDialect;

test('emits sqlite json1 expressions by default', function () {
    $sql = new AnalyticsJsonSqlDialect('sqlite');

    expect($sql->jsonExtract('meta', 'model'))->toBe('json_extract(meta, "$.model")');
    expect($sql->jsonIntValue('usage', 'prompt_tokens'))
        ->toBe('CAST(json_extract(usage, "$.prompt_tokens") AS INTEGER)');
    expect($sql->jsonValid('meta'))->toBe('json_valid(meta)');
    expect($sql->dateExpression('created_at'))->toBe('DATE(created_at)');
});

test('emits postgres jsonb expressions when driver is pgsql', function () {
    $sql = new AnalyticsJsonSqlDialect('pgsql');

    expect($sql->jsonExtract('meta', 'model'))->toBe("meta::jsonb->>'model'");
    expect($sql->jsonIntValue('usage', 'prompt_tokens'))
        ->toBe("(usage::jsonb->>'prompt_tokens')::integer");
    expect($sql->jsonValid('meta'))->toBe("meta IS NOT NULL AND meta::text != ''");
    expect($sql->dateExpression('created_at'))->toBe('created_at::date');
});
