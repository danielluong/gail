<?php

use App\Ai\Support\TextChunker;

test('empty text returns an empty array', function () {
    expect((new TextChunker)->chunk(''))->toBe([]);
    expect((new TextChunker)->chunk('   '))->toBe([]);
});

test('short text that fits in one chunk is returned as-is', function () {
    $text = 'This is a short paragraph.';

    $chunks = (new TextChunker(chunkSize: 1000))->chunk($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe($text);
});

test('text is split into multiple chunks at paragraph boundaries', function () {
    $paragraphs = [];
    for ($i = 0; $i < 10; $i++) {
        $paragraphs[] = str_repeat("Sentence {$i}. ", 20);
    }
    $text = implode("\n\n", $paragraphs);

    $chunks = (new TextChunker(chunkSize: 500, overlap: 100))->chunk($text);

    expect($chunks)->not->toBeEmpty();
    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(600);
    }
});

test('overlap carries context between chunks', function () {
    $a = str_repeat('A', 500);
    $b = str_repeat('B', 500);
    $c = str_repeat('C', 500);
    $text = "{$a}\n\n{$b}\n\n{$c}";

    $chunks = (new TextChunker(chunkSize: 600, overlap: 200))->chunk($text);

    expect(count($chunks))->toBeGreaterThanOrEqual(2);

    // At least some overlap text should appear across chunk boundaries
    if (count($chunks) >= 2) {
        $tailOfFirst = mb_substr($chunks[0], -100);
        expect($chunks[1])->toContain(mb_substr($tailOfFirst, 0, 50));
    }
});

test('long single paragraph is split by sentences', function () {
    $sentences = [];
    for ($i = 0; $i < 20; $i++) {
        $sentences[] = "This is sentence number {$i} with enough content to matter.";
    }
    $text = implode(' ', $sentences);

    $chunks = (new TextChunker(chunkSize: 300, overlap: 50))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(400);
    }
});

test('custom chunk size and overlap are respected', function () {
    $text = str_repeat('word ', 500);

    $chunks = (new TextChunker(chunkSize: 200, overlap: 50))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(5);
});
