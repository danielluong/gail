<?php

use App\Actions\Chat\ResolveChatAgent;
use App\Ai\Agents\ChatAgent;
use App\Ai\Context\ProjectScope;
use App\Models\Conversation;
use App\Models\Project;
use App\Support\GuestUser;

function resolver(): ResolveChatAgent
{
    return app(ResolveChatAgent::class);
}

test('resolves a new agent when no conversation id is supplied', function () {
    $project = Project::factory()->create();

    [$agent, $projectId] = resolver()->execute(null, $project->id, null);

    expect($agent)->toBeInstanceOf(ChatAgent::class);
    expect($projectId)->toBe($project->id);
    expect(app(ProjectScope::class)->id())->toBe($project->id);
});

test('prefers the project id persisted on the conversation when continuing a chat', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => $project->id]);

    [, $projectId] = resolver()->execute($conversation->id, $otherProject->id, null);

    expect($projectId)->toBe($project->id);
});

test('falls back to the requested project id when the conversation has none', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => null]);

    [, $projectId] = resolver()->execute($conversation->id, $project->id, null);

    expect($projectId)->toBe($project->id);
});

test('applies an explicit temperature to the agent', function () {
    [$agent] = resolver()->execute(null, null, 0.2);

    expect($agent->providerOptions('ollama'))->toBe(['temperature' => 0.2]);
});

test('leaves temperature unset when none is supplied', function () {
    [$agent] = resolver()->execute(null, null, null);

    expect($agent->providerOptions('ollama'))->toBe([]);
});

test('anonymous requests use the GuestUser value object', function () {
    [$agent] = resolver()->execute(null, null, null);

    $participant = $agent->conversationParticipant();

    expect($participant)->toBeInstanceOf(GuestUser::class);
    expect($participant->id)->toBe(0);
});
