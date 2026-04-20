<?php

use App\Ai\Contracts\DisplayableTool;

beforeEach(function () {
    config()->set('ai.default_for_images', 'openai');
    reregisterAiServiceProvider();
});

test('the home page shares a toolLabels map containing every registered displayable tool', function () {
    $response = $this->get(route('home'))->assertOk();

    $shared = $response->viewData('page')['props']['toolLabels'] ?? null;

    expect($shared)->toBeArray()->not->toBeEmpty();

    $expected = [];
    foreach (['ai.tools.core', 'ai.tools.chat', 'ai.tools.mysql_database'] as $tag) {
        foreach (app()->tagged($tag) as $tool) {
            if ($tool instanceof DisplayableTool) {
                $expected[class_basename($tool)] = $tool->label();
            }
        }
    }

    expect($shared)->toBe($expected);
});

test('toolLabels includes every currently-registered tool', function () {
    $response = $this->get(route('home'))->assertOk();

    $shared = $response->viewData('page')['props']['toolLabels'];

    expect($shared)->toHaveKeys([
        'Calculator',
        'CurrentDateTime',
        'CurrentLocation',
        'GenerateImage',
        'ManageNotes',
        'SearchProjectDocuments',
        'Weather',
        'WebFetch',
        'WebSearch',
        'Wikipedia',
        'ConnectToDatabaseTool',
        'ListTablesTool',
        'DescribeTableTool',
        'RunSelectQueryTool',
        'ExplainQueryTool',
        'SuggestIndexesTool',
        'AnalyzeSchemaTool',
        'ExportQueryCsvTool',
    ]);
});
