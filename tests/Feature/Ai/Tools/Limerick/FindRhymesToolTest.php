<?php

use App\Ai\Tools\Limerick\FindRhymesTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('find rhymes tool returns enriched rhyme list', function () {
    Http::fake([
        'api.datamuse.com/words?rel_rhy=*' => Http::response([
            ['word' => 'day'],
            ['word' => 'play'],
            ['word' => 'away'],
        ]),
        'api.datamuse.com/words?sp=day*' => Http::response([
            ['word' => 'day', 'tags' => ['pron:D EY1']],
        ]),
        'api.datamuse.com/words?sp=play*' => Http::response([
            ['word' => 'play', 'tags' => ['pron:P L EY1']],
        ]),
        'api.datamuse.com/words?sp=away*' => Http::response([
            ['word' => 'away', 'tags' => ['pron:AH0 W EY1']],
        ]),
    ]);

    $tool = app(FindRhymesTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'way'])), true);

    expect($result['seed'])->toBe('way')
        ->and($result['rhymes'])->toHaveCount(3)
        ->and($result['rhymes'][0])->toHaveKeys(['word', 'syllables', 'stress']);
});

test('find rhymes tool filters by syllable count', function () {
    Http::fake([
        'api.datamuse.com/words?rel_rhy=*' => Http::response([
            ['word' => 'day'],
            ['word' => 'away'],
        ]),
        'api.datamuse.com/words?sp=day*' => Http::response([
            ['word' => 'day', 'tags' => ['pron:D EY1']],
        ]),
        'api.datamuse.com/words?sp=away*' => Http::response([
            ['word' => 'away', 'tags' => ['pron:AH0 W EY1']],
        ]),
    ]);

    $tool = app(FindRhymesTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'way', 'syllables' => 2])), true);

    expect($result['rhymes'])->toHaveCount(1)
        ->and($result['rhymes'][0]['word'])->toBe('away');
});

test('find rhymes tool filters by stress pattern', function () {
    Http::fake([
        'api.datamuse.com/words?rel_rhy=*' => Http::response([
            ['word' => 'day'],
            ['word' => 'away'],
        ]),
        'api.datamuse.com/words?sp=day*' => Http::response([
            ['word' => 'day', 'tags' => ['pron:D EY1']],
        ]),
        'api.datamuse.com/words?sp=away*' => Http::response([
            ['word' => 'away', 'tags' => ['pron:AH0 W EY1']],
        ]),
    ]);

    $tool = app(FindRhymesTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'way', 'stress' => '0-1'])), true);

    expect($result['rhymes'])->toHaveCount(1)
        ->and($result['rhymes'][0]['word'])->toBe('away');
});

test('find rhymes tool rejects stress pattern that does not match syllable count', function () {
    $tool = app(FindRhymesTool::class);
    $result = json_decode($tool->handle(new Request([
        'word' => 'glee',
        'syllables' => 1,
        'stress' => '0-1',
    ])), true);

    expect($result['rhymes'])->toBe([])
        ->and($result['error'])->toContain('stress "0-1" has 2 digit(s) but syllables is 1');
});

test('find rhymes tool returns empty for unknown word', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([]),
    ]);

    $tool = app(FindRhymesTool::class);
    $result = json_decode($tool->handle(new Request(['word' => 'xyzzy'])), true);

    expect($result['rhymes'])->toBe([]);
});
