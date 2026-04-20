<?php

use App\Ai\Agents\ChatAgent;
use App\Ai\Tools\Chat\Calculator;
use App\Ai\Tools\Chat\CurrentDateTime;
use App\Ai\Tools\Chat\CurrentLocation;
use App\Ai\Tools\Chat\GenerateImage;
use App\Ai\Tools\Chat\ManageNotes;
use App\Ai\Tools\Chat\SearchProjectDocuments;
use App\Ai\Tools\Chat\Weather;
use App\Ai\Tools\Chat\WebFetch;
use App\Ai\Tools\Chat\WebSearch;
use App\Ai\Tools\Chat\Wikipedia;
use Laravel\Ai\Contracts\Tool;

test('chat agent resolves every expected tool across the core and chat tags', function () {
    config()->set('ai.default_for_images', 'openai');
    reregisterAiServiceProvider();

    $tools = (new ChatAgent)->tools();

    expect($tools)->toBeArray()->toHaveCount(10);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }

    $classes = array_map('get_class', $tools);

    expect($classes)->toEqualCanonicalizing([
        ManageNotes::class,
        SearchProjectDocuments::class,
        Calculator::class,
        CurrentDateTime::class,
        CurrentLocation::class,
        GenerateImage::class,
        Weather::class,
        WebFetch::class,
        WebSearch::class,
        Wikipedia::class,
    ]);
});

test('tool discovery order is deterministic: core tag first, then the agents tools in registration order', function () {
    config()->set('ai.default_for_images', 'openai');
    reregisterAiServiceProvider();

    $classes = array_map('get_class', (new ChatAgent)->tools());

    expect($classes)->toBe([
        ManageNotes::class,
        SearchProjectDocuments::class,
        Calculator::class,
        CurrentDateTime::class,
        CurrentLocation::class,
        GenerateImage::class,
        Weather::class,
        WebFetch::class,
        WebSearch::class,
        Wikipedia::class,
    ]);
});

test('GenerateImage is not registered when ai.default_for_images is null', function () {
    config()->set('ai.default_for_images', null);
    reregisterAiServiceProvider();

    $classes = array_map('get_class', (new ChatAgent)->tools());

    expect($classes)->not->toContain(GenerateImage::class)
        ->and($classes)->toHaveCount(9);
});
