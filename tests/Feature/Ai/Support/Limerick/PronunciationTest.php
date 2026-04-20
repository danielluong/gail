<?php

use App\Ai\Support\Limerick\Pronunciation;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->pronunciation = new Pronunciation;
});

test('lookup parses arpabet stress pattern from datamuse response', function () {
    Http::fake([
        'api.datamuse.com/words?sp=banana*' => Http::response([
            ['word' => 'banana', 'tags' => ['pron:B AH0 N AE1 N AH0']],
        ]),
    ]);

    $result = $this->pronunciation->lookup('banana');

    expect($result)->not->toBeNull()
        ->and($result['syllables'])->toBe(3)
        ->and($result['stress'])->toBe('0-1-0')
        ->and($result['arpabet'])->toBe('B AH0 N AE1 N AH0');
});

test('lookup normalizes secondary stress to primary', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'education', 'tags' => ['pron:EH2 JH AH0 K EY1 SH AH0 N']],
        ]),
    ]);

    $result = $this->pronunciation->lookup('education');

    expect($result['stress'])->toBe('1-0-1-0');
});

test('lookup returns null for empty word', function () {
    expect($this->pronunciation->lookup(''))->toBeNull();
});

test('lookup returns null when datamuse returns empty', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([]),
    ]);

    expect($this->pronunciation->lookup('zzzzxxx'))->toBeNull();
});

test('lookup caches results within same instance', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'cat', 'tags' => ['pron:K AE1 T']],
        ]),
    ]);

    $this->pronunciation->lookup('cat');
    $this->pronunciation->lookup('cat');

    Http::assertSentCount(1);
});

test('syllables returns count from lookup', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'umbrella', 'tags' => ['pron:AH0 M B R EH1 L AH0']],
        ]),
    ]);

    expect($this->pronunciation->syllables('umbrella'))->toBe(3);
});

test('syllables defaults to 1 for unknown words', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([]),
    ]);

    expect($this->pronunciation->syllables('xyzzy'))->toBe(1);
});

test('stressPattern returns pattern string', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'hello', 'tags' => ['pron:HH AH0 L OW1']],
        ]),
    ]);

    expect($this->pronunciation->stressPattern('hello'))->toBe('0-1');
});

test('rhymesFor returns list of rhyming words', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'day'],
            ['word' => 'play'],
            ['word' => 'say'],
        ]),
    ]);

    $rhymes = $this->pronunciation->rhymesFor('way');

    expect($rhymes)->toBe(['day', 'play', 'say']);
});

test('rhymesFor caches results', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'day'],
        ]),
    ]);

    $this->pronunciation->rhymesFor('way');
    $this->pronunciation->rhymesFor('way');

    Http::assertSentCount(1);
});

test('rhymesFor filters out single-character words', function () {
    Http::fake([
        'api.datamuse.com/words*' => Http::response([
            ['word' => 'a'],
            ['word' => 'day'],
        ]),
    ]);

    expect($this->pronunciation->rhymesFor('way'))->toBe(['day']);
});
