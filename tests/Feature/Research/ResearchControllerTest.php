<?php

use App\Ai\Agents\Research\CriticAgent;
use App\Ai\Agents\Research\EditorAgent;
use App\Ai\Agents\Research\ResearcherAgent;

test('POST /research validates the query field', function () {
    $this->postJson(route('research.run'), [])->assertUnprocessable();
    $this->postJson(route('research.run'), ['query' => 'x'])->assertUnprocessable();
    $this->postJson(route('research.run'), ['query' => str_repeat('a', 2001)])->assertUnprocessable();
});

test('POST /research returns the orchestrator bundle as JSON', function () {
    ResearcherAgent::fake([
        json_encode([
            'query' => 'solar vs nuclear',
            'subtopics' => ['cost'],
            'findings' => [[
                'topic' => 'cost',
                'facts' => ['solar is cheaper'],
                'sources' => ['https://ex.test'],
            ]],
            'conflicts' => [],
        ]),
    ]);
    EditorAgent::fake(['## Summary\n\nSolar wins.']);
    CriticAgent::fake([json_encode([
        'approved' => true,
        'confidence' => 'high',
    ])]);

    $response = $this->postJson(route('research.run'), [
        'query' => 'solar vs nuclear',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'answer',
        'research' => ['query', 'findings', 'subtopics', 'conflicts'],
        'critic' => ['approved', 'confidence'],
        'iterations',
        'warnings',
    ]);

    expect($response->json('answer'))->toContain('Solar wins');
    expect($response->json('critic.approved'))->toBeTrue();
});
