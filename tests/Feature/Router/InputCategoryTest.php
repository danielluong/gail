<?php

use App\Enums\InputCategory;

test('tryFromString accepts canonical lowercase values', function () {
    expect(InputCategory::tryFromString('question'))->toBe(InputCategory::Question);
    expect(InputCategory::tryFromString('task'))->toBe(InputCategory::Task);
    expect(InputCategory::tryFromString('chat'))->toBe(InputCategory::Chat);
});

test('tryFromString trims surrounding whitespace', function () {
    expect(InputCategory::tryFromString("  question\n"))->toBe(InputCategory::Question);
    expect(InputCategory::tryFromString("\ttask "))->toBe(InputCategory::Task);
});

test('tryFromString is case-insensitive', function () {
    expect(InputCategory::tryFromString('QUESTION'))->toBe(InputCategory::Question);
    expect(InputCategory::tryFromString('Task'))->toBe(InputCategory::Task);
    expect(InputCategory::tryFromString('ChAt'))->toBe(InputCategory::Chat);
});

test('tryFromString returns null for unknown values', function () {
    expect(InputCategory::tryFromString('greeting'))->toBeNull();
    expect(InputCategory::tryFromString(''))->toBeNull();
    expect(InputCategory::tryFromString(null))->toBeNull();
    expect(InputCategory::tryFromString('   '))->toBeNull();
});
