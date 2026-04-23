<?php

use App\Actions\Research\RunResearchAssistant;
use App\Ai\Agents\Research\CriticAgent;
use App\Ai\Agents\Research\EditorAgent;
use App\Ai\Agents\Research\ResearcherAgent;

test('runs Researcher -> Editor -> Critic and returns the bundled payload when approved', function () {
    ResearcherAgent::fake([
        json_encode([
            'query' => 'solar vs nuclear',
            'subtopics' => ['cost', 'emissions'],
            'findings' => [
                [
                    'topic' => 'cost',
                    'facts' => ['solar is cheaper per kWh over 20 years'],
                    'sources' => ['https://example.com/1'],
                ],
            ],
            'conflicts' => [],
        ]),
    ]);

    EditorAgent::fake(['## Summary\n\nSolar wins on cost.']);

    CriticAgent::fake([
        json_encode([
            'approved' => true,
            'issues' => [],
            'missing_topics' => [],
            'improvement_suggestions' => [],
            'confidence' => 'high',
        ]),
    ]);

    $result = app(RunResearchAssistant::class)->execute('solar vs nuclear');

    expect($result)
        ->toHaveKey('answer')
        ->toHaveKey('research')
        ->toHaveKey('critic')
        ->toHaveKey('iterations', 1);

    expect($result['answer'])->toContain('Solar wins on cost');
    expect($result['critic']['approved'])->toBeTrue();
    expect($result['research']['findings'][0]['topic'])->toBe('cost');
});

test('retries once when the Critic rejects the first draft', function () {
    ResearcherAgent::fake([
        json_encode([
            'query' => 'x',
            'subtopics' => ['first'],
            'findings' => [[
                'topic' => 'first',
                'facts' => ['fact1'],
                'sources' => ['https://a.test'],
            ]],
            'conflicts' => [],
        ]),
        json_encode([
            'query' => 'x',
            'subtopics' => ['second'],
            'findings' => [[
                'topic' => 'second',
                'facts' => ['fact2'],
                'sources' => ['https://b.test'],
            ]],
            'conflicts' => [],
        ]),
    ]);

    EditorAgent::fake([
        '## Summary\n\nFirst draft.',
        '## Summary\n\nSecond draft with more detail.',
    ]);

    CriticAgent::fake([
        json_encode([
            'approved' => false,
            'issues' => ['missing second topic'],
            'missing_topics' => ['second'],
            'improvement_suggestions' => ['cover second angle'],
            'confidence' => 'medium',
        ]),
        json_encode([
            'approved' => true,
            'issues' => [],
            'missing_topics' => [],
            'improvement_suggestions' => [],
            'confidence' => 'high',
        ]),
    ]);

    $result = app(RunResearchAssistant::class)->execute('x');

    expect($result['iterations'])->toBe(2);
    expect($result['answer'])->toContain('Second draft');
    expect($result['critic']['approved'])->toBeTrue();
    // Merged research should contain both topics from the two Researcher runs.
    $topics = array_column($result['research']['findings'], 'topic');
    expect($topics)->toContain('first')->toContain('second');
});

test('stops after one retry even if the Critic still rejects', function () {
    ResearcherAgent::fake([
        json_encode(['query' => 'x', 'subtopics' => [], 'findings' => [], 'conflicts' => []]),
        json_encode(['query' => 'x', 'subtopics' => [], 'findings' => [], 'conflicts' => []]),
    ]);

    EditorAgent::fake(['draft one', 'draft two']);

    CriticAgent::fake([
        json_encode(['approved' => false, 'missing_topics' => ['a']]),
        json_encode(['approved' => false, 'missing_topics' => ['b']]),
    ]);

    $result = app(RunResearchAssistant::class)->execute('x');

    expect($result['iterations'])->toBe(2);
    expect($result['answer'])->toBe('draft two');
});

test('soft-fails when the Researcher returns non-JSON', function () {
    ResearcherAgent::fake(['sorry, I could not structure the output']);
    EditorAgent::fake(['## Summary\n\nIncomplete.']);
    CriticAgent::fake([json_encode(['approved' => true, 'confidence' => 'low'])]);

    $result = app(RunResearchAssistant::class)->execute('x');

    expect($result['research']['findings'])->toBe([]);
    expect($result['warnings'])->not->toBeEmpty();
});
