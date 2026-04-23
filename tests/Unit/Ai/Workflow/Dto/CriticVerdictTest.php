<?php

use App\Ai\Workflow\Dto\CriticVerdict;

test('approvedFallback produces a low-confidence approval with the caller warning', function () {
    $verdict = CriticVerdict::approvedFallback(['critic call timed out']);

    expect($verdict->approved)->toBeTrue();
    expect($verdict->confidence)->toBe('low');
    expect($verdict->issues)->toBe([]);
    expect($verdict->missing)->toBe([]);
    expect($verdict->warnings)->toBe(['critic call timed out']);
});

test('fromRawAgentResponse folds missing_topics + improvement_suggestions + missing into a unified list', function () {
    $verdict = CriticVerdict::fromRawAgentResponse([
        'approved' => false,
        'issues' => ['issue A'],
        'missing_topics' => ['topic 1', 'topic 2'],
        'improvement_suggestions' => ['suggestion X'],
        'missing' => ['carry-through'],
        'confidence' => 'high',
    ]);

    expect($verdict->approved)->toBeFalse();
    expect($verdict->missingTopics)->toBe(['topic 1', 'topic 2']);
    expect($verdict->improvementSuggestions)->toBe(['suggestion X']);
    expect($verdict->missing)->toBe([
        'topic 1', 'topic 2', 'suggestion X', 'carry-through',
    ]);
    expect($verdict->confidence)->toBe('high');
});

test('fromRawAgentResponse drops non-string entries and empty trimmed strings from list fields', function () {
    $verdict = CriticVerdict::fromRawAgentResponse([
        'approved' => false,
        'issues' => ['ok', 42, null],
        'missing_topics' => ['real topic', '   '],
        'confidence' => 'medium',
    ]);

    expect($verdict->issues)->toBe(['ok']);
    expect($verdict->missing)->toBe(['real topic']);
});

test('fromRawAgentResponse coerces unknown confidence levels to medium', function () {
    $verdict = CriticVerdict::fromRawAgentResponse([
        'approved' => true,
        'confidence' => 'extremely-high',
    ]);

    expect($verdict->confidence)->toBe('medium');
});

test('fromRawAgentResponse treats a missing approved as false', function () {
    $verdict = CriticVerdict::fromRawAgentResponse([]);

    expect($verdict->approved)->toBeFalse();
    expect($verdict->confidence)->toBe('medium');
});

test('toArray round-trips the full seven-field shape', function () {
    $verdict = new CriticVerdict(
        approved: true,
        issues: ['a'],
        missing: ['b'],
        missingTopics: ['b'],
        improvementSuggestions: [],
        confidence: 'high',
        warnings: ['w'],
    );

    expect($verdict->toArray())->toBe([
        'approved' => true,
        'issues' => ['a'],
        'missing' => ['b'],
        'missing_topics' => ['b'],
        'improvement_suggestions' => [],
        'confidence' => 'high',
        'warnings' => ['w'],
    ]);
});

test('fromArray reconstructs a verdict without re-flattening the missing list', function () {
    // When the kernel stashes a serialized verdict as `critic_feedback`
    // and a later caller needs a DTO again, fromArray must take the
    // already-flattened `missing` list verbatim rather than repeating
    // the union with missing_topics / improvement_suggestions (which
    // would double-count the topic entries).
    $serialized = [
        'approved' => false,
        'issues' => ['i'],
        'missing' => ['pre-merged gap'],
        'missing_topics' => ['topic X'],
        'improvement_suggestions' => ['suggestion Y'],
        'confidence' => 'low',
        'warnings' => [],
    ];

    $verdict = CriticVerdict::fromArray($serialized);

    expect($verdict->missing)->toBe(['pre-merged gap']);
    expect($verdict->missingTopics)->toBe(['topic X']);
    expect($verdict->improvementSuggestions)->toBe(['suggestion Y']);
});
