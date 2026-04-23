<?php

use App\Ai\Agents\Research\CriticAgent;
use App\Ai\Workflow\Critics\CriticAgentEvaluator;

test('returns a structured verdict on a valid critic reply', function () {
    CriticAgent::fake([
        json_encode([
            'approved' => true,
            'issues' => ['a minor nit'],
            'missing_topics' => ['topic A'],
            'improvement_suggestions' => ['tighten the intro'],
            'confidence' => 'high',
        ]),
    ]);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'some answer',
    ]);

    expect($verdict->approved)->toBeTrue();
    expect($verdict->issues)->toBe(['a minor nit']);
    expect($verdict->missing)->toBe(['topic A', 'tighten the intro']);
    expect($verdict->confidence)->toBe('high');
    expect($verdict->warnings)->toBe([]);
});

test('flattens missing_topics and improvement_suggestions onto the missing key', function () {
    CriticAgent::fake([
        json_encode([
            'approved' => false,
            'missing_topics' => ['topic 1', 'topic 2'],
            'improvement_suggestions' => ['add examples'],
            'confidence' => 'medium',
        ]),
    ]);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'x',
    ]);

    expect($verdict->approved)->toBeFalse();
    expect($verdict->missing)->toBe(['topic 1', 'topic 2', 'add examples']);
});

test('defaults to approved with a warning when the critic returns non-JSON', function () {
    CriticAgent::fake(['sorry, I cannot judge this']);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'x',
    ]);

    expect($verdict->approved)->toBeTrue();
    expect($verdict->confidence)->toBe('low');
    expect($verdict->warnings)->not->toBeEmpty();
    expect($verdict->warnings[0])->toContain('non-JSON');
});

test('coerces an unknown confidence level to medium', function () {
    CriticAgent::fake([
        json_encode([
            'approved' => true,
            'issues' => [],
            'missing_topics' => [],
            'improvement_suggestions' => [],
            'confidence' => 'extremely-high',
        ]),
    ]);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'x',
    ]);

    expect($verdict->confidence)->toBe('medium');
});

test('passes research findings to the critic when present', function () {
    CriticAgent::fake([
        json_encode(['approved' => true, 'confidence' => 'high']),
    ]);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'answer',
        'research' => ['findings' => [['topic' => 't', 'facts' => ['f'], 'sources' => []]]],
    ]);

    expect($verdict->approved)->toBeTrue();
    CriticAgent::assertPrompted(fn ($prompt) => str_contains((string) $prompt->prompt, 'Research findings'));
});

test('toArray round-trips all seven verdict fields', function () {
    CriticAgent::fake([
        json_encode([
            'approved' => false,
            'issues' => ['issue A'],
            'missing_topics' => ['topic 1'],
            'improvement_suggestions' => ['suggestion 1'],
            'confidence' => 'medium',
        ]),
    ]);

    $verdict = (new CriticAgentEvaluator)->evaluate([
        'query' => 'test',
        'response' => 'x',
    ]);

    $serialized = $verdict->toArray();

    expect($serialized)->toMatchArray([
        'approved' => false,
        'issues' => ['issue A'],
        'missing' => ['topic 1', 'suggestion 1'],
        'missing_topics' => ['topic 1'],
        'improvement_suggestions' => ['suggestion 1'],
        'confidence' => 'medium',
    ]);
    expect($serialized['warnings'])->toBe([]);
});
