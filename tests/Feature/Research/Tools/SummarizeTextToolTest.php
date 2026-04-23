<?php

use App\Ai\Agents\Research\LlmCallerAgent;
use App\Ai\Tools\Research\SummarizeTextTool;
use Laravel\Ai\Tools\Request;

test('returns error when text is empty', function () {
    $result = (string) (new SummarizeTextTool)->handle(new Request(['text' => '  ']));

    expect($result)->toContain('Error: No text provided');
});

test('calls the LLM and returns the summary text', function () {
    LlmCallerAgent::fake(['A short, focused summary.']);

    $result = (string) (new SummarizeTextTool)->handle(new Request([
        'text' => str_repeat('Some text to summarize. ', 50),
        'max_length' => 200,
    ]));

    expect($result)->toBe('A short, focused summary.');
});

test('direct summarize() method returns the summary string', function () {
    LlmCallerAgent::fake(['direct summary']);

    $summary = (new SummarizeTextTool)->summarize('some long text');

    expect($summary)->toBe('direct summary');
});
