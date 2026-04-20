<?php

use App\Ai\Tools\Limerick\PronounceWordTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('pronounce word tool returns syllables and stress', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'banana', 'tags' => ['pron:B AH0 N AE1 N AH0']],
        ]),
    ]);

    $tool = app(PronounceWordTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'banana'])), true);

    expect($result['word'])->toBe('banana')
        ->and($result['syllables'])->toBe(3)
        ->and($result['stress'])->toBe('0-1-0');
});

test('pronounce word tool returns error for unknown word', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([]),
    ]);

    $tool = app(PronounceWordTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'flibberty'])), true);

    expect($result['error'])->toBe('not_found');
});

test('pronounce word tool returns error for empty input', function () {
    $tool = app(PronounceWordTool::class);
    $result = json_decode($tool->handle(new Request(['word' => ''])), true);

    expect($result['error'])->toBe('empty_word');
});
