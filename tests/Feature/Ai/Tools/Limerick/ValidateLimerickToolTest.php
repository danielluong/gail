<?php

use App\Ai\Tools\Limerick\ValidateLimerickTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function fakeDatamuseForLimerick(): void
{
    Http::fake([
        'api.datamuse.com/words?sp=*' => function ($request) {
            $word = $request->data()['sp'] ?? $request['sp'] ?? '';

            $pronunciations = [
                'there' => 'DH EH1 R',
                'once' => 'W AH1 N S',
                'was' => 'W AH1 Z',
                'a' => 'AH0',
                'fellow' => 'F EH1 L OW0',
                'named' => 'N EY1 M D',
                'reese' => 'R IY1 S',
                'whose' => 'HH UW1 Z',
                'car' => 'K AA1 R',
                'keys' => 'K IY1 Z',
                'would' => 'W UH1 D',
                'vanish' => 'V AE1 N IH0 SH',
                'with' => 'W IH1 DH',
                'ease' => 'IY1 Z',
                'he\'d' => 'HH IY1 D',
                'search' => 'S ER1 CH',
                'high' => 'HH AY1',
                'and' => 'AH0 N D',
                'low' => 'L OW1',
                'through' => 'TH R UW1',
                'each' => 'IY1 CH',
                'drawer' => 'D R AO1 R',
                'in' => 'IH0 N',
                'row' => 'R OW1',
                'till' => 'T IH1 L',
                'he' => 'HH IY1',
                'found' => 'F AW1 N D',
                'them' => 'DH EH1 M',
                'inside' => 'IH0 N S AY1 D',
                'his' => 'HH IH1 Z',
                'own' => 'OW1 N',
                'fleece' => 'F L IY1 S',
                'the' => 'DH AH0',
                'cat' => 'K AE1 T',
                'sat' => 'S AE1 T',
                'on' => 'AA1 N',
                'mat' => 'M AE1 T',
                'it' => 'IH1 T',
                'rained' => 'R EY1 N D',
                'then' => 'DH EH1 N',
                'snowed' => 'S N OW1 D',
            ];

            $pron = $pronunciations[$word] ?? null;
            if ($pron) {
                return Http::response([['word' => $word, 'tags' => ["pron:{$pron}"]]]);
            }

            return Http::response([]);
        },
        'api.datamuse.com/words?rel_rhy=*' => function ($request) {
            $word = $request->data()['rel_rhy'] ?? $request['rel_rhy'] ?? '';

            $rhymes = [
                'reese' => [['word' => 'ease'], ['word' => 'fleece'], ['word' => 'peace']],
                'ease' => [['word' => 'reese'], ['word' => 'fleece'], ['word' => 'peace']],
                'fleece' => [['word' => 'reese'], ['word' => 'ease'], ['word' => 'peace']],
                'low' => [['word' => 'row'], ['word' => 'go'], ['word' => 'show']],
                'row' => [['word' => 'low'], ['word' => 'go'], ['word' => 'show']],
                'sat' => [['word' => 'cat'], ['word' => 'mat'], ['word' => 'hat']],
                'cat' => [['word' => 'sat'], ['word' => 'mat'], ['word' => 'hat']],
                'mat' => [['word' => 'sat'], ['word' => 'cat'], ['word' => 'hat']],
                'snowed' => [['word' => 'rained']],
                'rained' => [['word' => 'snowed']],
            ];

            return Http::response($rhymes[$word] ?? []);
        },
    ]);
}

test('validator rejects non-5-line input', function () {
    $tool = app(ValidateLimerickTool::class);
    $result = json_decode($tool->handle(new Request(['lines' => ['one', 'two', 'three']])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('5 lines');
});

test('validator checks meter for each line', function () {
    fakeDatamuseForLimerick();

    $tool = app(ValidateLimerickTool::class);
    $result = json_decode($tool->handle(new Request(['lines' => [
        'There once was a fellow named Reese',
        'Whose car keys would vanish with ease',
        "He'd search high and low",
        'Through each drawer in a row',
        'Till he found them inside his own fleece',
    ]])), true);

    expect($result['lines'])->toHaveCount(5);

    foreach ($result['lines'] as $line) {
        expect($line)->toHaveKeys(['index', 'syllables', 'stresses', 'target', 'ok', 'issues']);
    }
});

test('validator checks rhyme scheme', function () {
    fakeDatamuseForLimerick();

    $tool = app(ValidateLimerickTool::class);
    $result = json_decode($tool->handle(new Request(['lines' => [
        'There once was a fellow named Reese',
        'Whose car keys would vanish with ease',
        "He'd search high and low",
        'Through each drawer in a row',
        'Till he found them inside his own fleece',
    ]])), true);

    expect($result['rhyme'])->toHaveKeys(['a_group_ok', 'b_group_ok', 'a_and_b_distinct']);
});

test('validator flags lines with wrong syllable count', function () {
    fakeDatamuseForLimerick();

    $tool = app(ValidateLimerickTool::class);
    $result = json_decode($tool->handle(new Request(['lines' => [
        'The cat sat',
        'The cat sat',
        'It rained',
        'Then snowed',
        'The cat sat on the mat',
    ]])), true);

    expect($result['ok'])->toBeFalse();

    $shortLines = collect($result['lines'])->filter(fn ($l) => ! $l['ok']);
    expect($shortLines)->not->toBeEmpty();
});
