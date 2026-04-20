<?php

use App\Ai\Context\ProjectScope;
use App\Ai\Tools\Chat\SearchProjectDocuments;
use App\Models\Project;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

function scopedSearchTool(?int $projectId = null): SearchProjectDocuments
{
    $scope = new ProjectScope;
    $scope->set($projectId);

    return new SearchProjectDocuments($scope);
}

test('returns a message when no project is set', function () {
    $tool = scopedSearchTool();

    $result = (string) $tool->handle(new Request(['query' => 'hello']));

    expect($result)->toContain('No project selected');
});

test('returns a message when the project has no indexed chunks', function () {
    $project = Project::factory()->create();

    $tool = scopedSearchTool($project->id);

    $result = (string) $tool->handle(new Request(['query' => 'anything']));

    expect($result)->toContain('no indexed documents');
});

test('returns an error when query is empty', function () {
    $tool = scopedSearchTool(1);

    $result = (string) $tool->handle(new Request(['query' => '']));

    expect($result)->toContain('No search query provided');
});

test('provides a valid label and description', function () {
    $tool = scopedSearchTool();

    expect($tool->label())->toBe('Searched project documents');
    expect((string) $tool->description())->toContain('project documents');
});

test('schema defines query as required and limit as optional', function () {
    $tool = scopedSearchTool();
    $jsonSchema = new JsonSchemaTypeFactory;
    $schema = $tool->schema($jsonSchema);

    expect($schema)->toHaveKey('query');
    expect($schema)->toHaveKey('limit');
});
