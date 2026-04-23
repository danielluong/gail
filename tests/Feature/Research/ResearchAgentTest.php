<?php

use App\Ai\Agents\Research\EditorAgent;
use App\Ai\Agents\Research\ResearchAgent;

test('base prompt is the Editor prompt when no research is attached', function () {
    $agent = new ResearchAgent;

    $instructions = (string) $agent->instructions();

    expect($instructions)->toContain('You are an Editor Agent');
    expect($instructions)->not->toContain('Research findings');
});

test('attached research is appended to the system prompt', function () {
    $agent = (new ResearchAgent)->withResearch('{"findings":[{"topic":"x"}]}');

    $instructions = (string) $agent->instructions();

    expect($instructions)
        ->toContain('Research findings')
        ->toContain('findings')
        ->toContain('Reminder');
});

test('editor prompt constant is shared with EditorAgent', function () {
    // Keeps the chat-UI answer and the JSON-endpoint answer in the same voice.
    $agent = new ResearchAgent;

    expect((string) $agent->instructions())->toContain(EditorAgent::PROMPT);
});

test('tools list is empty — research tools belong to the Researcher only', function () {
    $agent = new ResearchAgent;

    expect($agent->tools())->toBe([]);
});
