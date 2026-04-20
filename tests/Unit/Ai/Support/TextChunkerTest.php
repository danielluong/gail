<?php

use App\Ai\Support\TextChunker;

test('returns empty array for blank input', function () {
    $chunker = new TextChunker;

    expect($chunker->chunk(''))->toBe([]);
    expect($chunker->chunk("   \n\n   "))->toBe([]);
});

test('returns a single chunk when text fits within size', function () {
    $chunker = new TextChunker(chunkSize: 1000, overlap: 100);

    $chunks = $chunker->chunk('Short text.');

    expect($chunks)->toBe(['Short text.']);
});

test('splits long text into multiple chunks respecting size limit', function () {
    $chunker = new TextChunker(chunkSize: 100, overlap: 20);

    $paragraph = str_repeat('alpha bravo charlie delta. ', 20);

    $chunks = $chunker->chunk($paragraph);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(200);
    }
});

test('prefers paragraph boundaries when splitting', function () {
    $chunker = new TextChunker(chunkSize: 50, overlap: 10);

    $text = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

    $chunks = $chunker->chunk($text);

    expect($chunks)->not->toBeEmpty();
});

test('falls back to sentence splitting for long paragraphs', function () {
    $chunker = new TextChunker(chunkSize: 60, overlap: 10);

    $text = 'Sentence one is here. Sentence two is here. Sentence three is here. Sentence four is here.';

    $chunks = $chunker->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1);
});
