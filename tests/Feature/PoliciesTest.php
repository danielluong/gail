<?php

use App\Models\Conversation;
use App\Models\Project;
use App\Policies\ConversationPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;

test('ConversationPolicy stub allows every action for any user', function () {
    $policy = new ConversationPolicy;
    $conversation = Conversation::factory()->create();

    expect($policy->viewAny(null))->toBeTrue();
    expect($policy->view(null, $conversation))->toBeTrue();
    expect($policy->create(null))->toBeTrue();
    expect($policy->update(null, $conversation))->toBeTrue();
    expect($policy->delete(null, $conversation))->toBeTrue();
});

test('ProjectPolicy stub allows every action for any user', function () {
    $policy = new ProjectPolicy;
    $project = Project::factory()->create();

    expect($policy->viewAny(null))->toBeTrue();
    expect($policy->view(null, $project))->toBeTrue();
    expect($policy->create(null))->toBeTrue();
    expect($policy->update(null, $project))->toBeTrue();
    expect($policy->delete(null, $project))->toBeTrue();
});

test('Gate resolves ConversationPolicy by naming convention', function () {
    $conversation = Conversation::factory()->create();

    expect(Gate::allows('update', $conversation))->toBeTrue();
    expect(Gate::allows('delete', $conversation))->toBeTrue();
});

test('Gate resolves ProjectPolicy by naming convention', function () {
    $project = Project::factory()->create();

    expect(Gate::allows('create', Project::class))->toBeTrue();
    expect(Gate::allows('update', $project))->toBeTrue();
    expect(Gate::allows('delete', $project))->toBeTrue();
});
