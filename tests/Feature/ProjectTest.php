<?php

use App\Models\Conversation;
use App\Models\Project;

test('store creates a new project', function () {
    $this->postJson(route('projects.store'), ['name' => 'My Project'])
        ->assertNoContent();

    $this->assertDatabaseHas('projects', ['name' => 'My Project']);
});

test('store validates name is required', function () {
    $this->postJson(route('projects.store'), ['name' => ''])
        ->assertUnprocessable();
});

test('update renames a project', function () {
    $project = Project::factory()->create(['name' => 'Old Name']);

    $this->patchJson(route('projects.update', $project->id), ['name' => 'New Name'])
        ->assertNoContent();

    expect($project->fresh()->name)->toBe('New Name');
});

test('update returns 404 for soft-deleted project', function () {
    $project = Project::factory()->create(['deleted_at' => now()]);

    $this->patchJson(route('projects.update', $project->id), ['name' => 'New'])
        ->assertNotFound();
});

test('update validates name is required', function () {
    $project = Project::factory()->create();

    $this->patchJson(route('projects.update', $project->id), ['name' => ''])
        ->assertUnprocessable();
});

test('destroy soft-deletes project and its conversations', function () {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->create(['project_id' => $project->id]);

    $this->deleteJson(route('projects.destroy', $project->id))
        ->assertNoContent();

    expect($project->fresh()->deleted_at)->not->toBeNull();
    expect($conversation->fresh()->deleted_at)->not->toBeNull();
});

test('destroy returns 404 for already deleted project', function () {
    $project = Project::factory()->create(['deleted_at' => now()]);

    $this->deleteJson(route('projects.destroy', $project->id))
        ->assertNotFound();
});
