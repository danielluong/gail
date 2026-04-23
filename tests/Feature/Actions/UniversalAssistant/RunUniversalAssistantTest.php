<?php

use App\Actions\UniversalAssistant\RunUniversalAssistant;
use App\Ai\Agents\Content\GeneratorAgent;
use App\Ai\Agents\Content\RewriterAgent;
use App\Ai\Agents\Research\CriticAgent;
use App\Ai\Agents\Research\EditorAgent;
use App\Ai\Agents\Research\ResearcherAgent;
use App\Ai\Agents\Router\ChatAgent as RouterChatAgent;
use App\Ai\Agents\Router\ClassifierAgent;

/**
 * Helper — a critic reply that approves the answer and triggers no retry.
 */
function criticApproves(): string
{
    return json_encode([
        'approved' => true,
        'issues' => [],
        'missing_topics' => [],
        'improvement_suggestions' => [],
        'confidence' => 'high',
    ]);
}

test('question path runs the research pipeline and approves in one iteration', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.9]),
    ]);
    ResearcherAgent::fake([
        json_encode([
            'query' => 'solar vs nuclear',
            'subtopics' => ['cost'],
            'findings' => [['topic' => 'cost', 'facts' => ['cheap'], 'sources' => ['https://x.test']]],
            'conflicts' => [],
        ]),
    ]);
    EditorAgent::fake(['## Summary\n\nSolar is cheaper.']);
    CriticAgent::fake([criticApproves()]);

    $result = app(RunUniversalAssistant::class)->execute('solar vs nuclear');

    expect($result['category'])->toBe('question');
    expect($result['confidence'])->toBe(0.9);
    expect($result['selected_path'])->toBe('research');
    expect($result['iterations'])->toBe(1);
    expect($result['response'])->toContain('Solar is cheaper');
    expect($result['critic']['approved'])->toBeTrue();
});

test('task path runs the content pipeline and approves in one iteration', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'task', 'confidence' => 0.85]),
    ]);
    GeneratorAgent::fake(['rough draft of the email']);
    RewriterAgent::fake(['Polished email draft.']);
    CriticAgent::fake([criticApproves()]);

    $result = app(RunUniversalAssistant::class)->execute('write me an email to my landlord');

    expect($result['category'])->toBe('task');
    expect($result['selected_path'])->toBe('content');
    expect($result['iterations'])->toBe(1);
    expect($result['response'])->toBe('Polished email draft.');
    expect($result['critic']['approved'])->toBeTrue();
});

test('chat path runs the chat specialist directly (no pipeline)', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'chat', 'confidence' => 0.8]),
    ]);
    RouterChatAgent::fake(['Hey! How can I help?']);
    CriticAgent::fake([criticApproves()]);

    $result = app(RunUniversalAssistant::class)->execute('hi there');

    expect($result['category'])->toBe('chat');
    expect($result['selected_path'])->toBe('chat');
    expect($result['response'])->toBe('Hey! How can I help?');
    expect($result['iterations'])->toBe(1);
});

test('low-confidence classification forces the chat path regardless of category', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.3]),
    ]);
    RouterChatAgent::fake(['I am not sure — tell me more?']);
    CriticAgent::fake([criticApproves()]);

    $result = app(RunUniversalAssistant::class)->execute('???');

    expect($result['category'])->toBe('question');  // classifier's raw answer
    expect($result['confidence'])->toBe(0.3);
    expect($result['selected_path'])->toBe('chat');  // but the router routed to chat
    expect($result['response'])->toContain('not sure');
});

test('critic rejection triggers one retry with feedback injected into the pipeline', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.9]),
    ]);
    ResearcherAgent::fake([
        json_encode(['query' => 'x', 'subtopics' => [], 'findings' => [], 'conflicts' => []]),
        json_encode(['query' => 'x', 'subtopics' => ['second'], 'findings' => [['topic' => 'second', 'facts' => ['f'], 'sources' => []]], 'conflicts' => []]),
    ]);
    EditorAgent::fake(['first draft', 'second draft']);
    CriticAgent::fake([
        json_encode([
            'approved' => false,
            'issues' => ['incomplete'],
            'missing_topics' => ['second topic'],
            'improvement_suggestions' => ['cover angle B'],
            'confidence' => 'medium',
        ]),
        criticApproves(),
    ]);

    $result = app(RunUniversalAssistant::class)->execute('solar vs nuclear');

    expect($result['iterations'])->toBe(2);
    expect($result['response'])->toBe('second draft');
    expect($result['critic']['approved'])->toBeTrue();

    // Second Researcher call should have received the critic's feedback
    // as additional bullets appended to the query.
    ResearcherAgent::assertPrompted(fn ($prompt) => str_contains((string) $prompt->prompt, 'second topic'));
});

test('retry is capped at one pass even when the critic keeps rejecting', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'task', 'confidence' => 0.9]),
    ]);
    GeneratorAgent::fake(['draft one', 'draft two']);
    RewriterAgent::fake(['polish one', 'polish two']);
    CriticAgent::fake([
        json_encode(['approved' => false, 'missing_topics' => ['x'], 'confidence' => 'low']),
        json_encode(['approved' => false, 'missing_topics' => ['y'], 'confidence' => 'low']),
    ]);

    $result = app(RunUniversalAssistant::class)->execute('write about x');

    expect($result['iterations'])->toBe(2);
    expect($result['response'])->toBe('polish two');
    expect($result['critic']['approved'])->toBeFalse();
});

test('empty input short-circuits without any LLM call', function () {
    ClassifierAgent::assertNeverPrompted();

    $result = app(RunUniversalAssistant::class)->execute('   ');

    expect($result['category'])->toBe('chat');
    expect($result['selected_path'])->toBe('chat');
    expect($result['response'])->toBe('');
    expect($result['iterations'])->toBe(0);
    expect($result['warnings'])->toContain('Empty input; nothing to classify.');
});

test('malformed classifier output falls back to chat with a warning', function () {
    ClassifierAgent::fake(['sorry, I could not classify']);
    RouterChatAgent::fake(['Let us chat.']);
    CriticAgent::fake([criticApproves()]);

    $result = app(RunUniversalAssistant::class)->execute('something');

    expect($result['selected_path'])->toBe('chat');
    expect($result['response'])->toBe('Let us chat.');
    expect($result['warnings'])->not->toBeEmpty();
});
