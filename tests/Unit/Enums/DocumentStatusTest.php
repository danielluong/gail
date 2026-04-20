<?php

use App\Enums\DocumentStatus;

test('backing values match the persisted strings', function () {
    expect(DocumentStatus::Pending->value)->toBe('pending');
    expect(DocumentStatus::Processing->value)->toBe('processing');
    expect(DocumentStatus::Ready->value)->toBe('ready');
    expect(DocumentStatus::Failed->value)->toBe('failed');
});

test('isPending covers both pre-run and in-flight states', function () {
    expect(DocumentStatus::Pending->isPending())->toBeTrue();
    expect(DocumentStatus::Processing->isPending())->toBeTrue();
    expect(DocumentStatus::Ready->isPending())->toBeFalse();
    expect(DocumentStatus::Failed->isPending())->toBeFalse();
});

test('isTerminal covers only final states', function () {
    expect(DocumentStatus::Ready->isTerminal())->toBeTrue();
    expect(DocumentStatus::Failed->isTerminal())->toBeTrue();
    expect(DocumentStatus::Pending->isTerminal())->toBeFalse();
    expect(DocumentStatus::Processing->isTerminal())->toBeFalse();
});
