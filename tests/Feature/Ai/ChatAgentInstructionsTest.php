<?php

use App\Ai\Agents\ChatAgent;
use App\Models\Note;
use App\Models\Project;

test('instructions returns the base prompt when no context is available', function () {
    $instructions = (string) (new ChatAgent)->instructions();

    expect($instructions)
        ->toContain('You are a helpful, honest, and safe AI assistant.')
        ->toContain('# Tool routing')
        ->toContain('describe what you see directly')
        ->not->toContain('## Saved Notes')
        ->not->toContain('## Current Project')
        ->not->toContain('## Project Instructions');
});

test('instructions appends saved notes when notes exist', function () {
    Note::factory()->create(['key' => 'name', 'value' => 'Daniel']);
    Note::factory()->create(['key' => 'project', 'value' => 'Gail']);

    $instructions = (string) (new ChatAgent)->instructions();

    expect($instructions)
        ->toContain('## Saved Notes (personal memory)')
        ->toContain('- name: Daniel')
        ->toContain('- project: Gail');
});

test('instructions includes project context and system prompt when a project is active', function () {
    $project = Project::factory()->create([
        'name' => 'Demo Project',
        'system_prompt' => 'Always respond in haiku.',
    ]);

    $instructions = (string) (new ChatAgent)
        ->forProject($project->id)
        ->instructions();

    expect($instructions)
        ->toContain('## Current Project')
        ->toContain('"Demo Project"')
        ->toContain("(ID: {$project->id})")
        ->toContain('## Project Instructions')
        ->toContain('Always respond in haiku.');
});

test('instructions preserves section order: base then notes then project', function () {
    Note::factory()->create(['key' => 'k', 'value' => 'v']);

    $project = Project::factory()->create([
        'name' => 'Ordered',
        'system_prompt' => 'PI',
    ]);

    $instructions = (string) (new ChatAgent)
        ->forProject($project->id)
        ->instructions();

    $notes = strpos($instructions, '## Saved Notes');
    $currentProject = strpos($instructions, '## Current Project');
    $projectInstructions = strpos($instructions, '## Project Instructions');

    expect($notes)->toBeInt()
        ->and($currentProject)->toBeGreaterThan($notes)
        ->and($projectInstructions)->toBeGreaterThan($currentProject);
});

test('forProject with null does not load any project context', function () {
    Project::factory()->create(['name' => 'Should Not Appear']);

    $instructions = (string) (new ChatAgent)
        ->forProject(null)
        ->instructions();

    expect($instructions)
        ->not->toContain('## Current Project')
        ->not->toContain('Should Not Appear');
});
