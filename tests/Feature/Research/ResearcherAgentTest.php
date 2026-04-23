<?php

use App\Ai\Agents\Research\ResearcherAgent;
use App\Ai\Tools\Research\ExtractFactsTool;
use App\Ai\Tools\Research\FetchPageTool;
use App\Ai\Tools\Research\SummarizeTextTool;
use App\Ai\Tools\Research\WebSearchTool;
use App\Ai\Tools\Research\WikipediaSearchTool;

test('Researcher exposes only the ai.tools.research-tagged tools', function () {
    $tools = (new ResearcherAgent)->tools();

    $classes = array_map(fn ($tool) => $tool::class, (array) $tools);

    expect($classes)
        ->toContain(WebSearchTool::class)
        ->toContain(WikipediaSearchTool::class)
        ->toContain(FetchPageTool::class)
        ->toContain(SummarizeTextTool::class)
        ->toContain(ExtractFactsTool::class);

    // No chat, core, limerick, or MySQL tools should leak in.
    foreach ($classes as $class) {
        expect($class)->toStartWith('App\\Ai\\Tools\\Research\\');
    }
});

test('instructions mandate strict JSON output and forbid writing a polished answer', function () {
    $instructions = (string) (new ResearcherAgent)->instructions();

    expect($instructions)
        ->toContain('Researcher Agent')
        ->toContain('STRICT JSON')
        ->toContain('Editor')
        ->toContain('sources');
});
