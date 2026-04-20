<?php

use App\Ai\Tools\Chat\GenerateImage;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config()->set('ai.default_for_images', 'openai');
    Storage::fake('public');
    Image::fake();
});

test('returns error when no prompt is provided', function () {
    $result = (string) (new GenerateImage)->handle(new Request([]));

    expect($result)->toContain('Error: A prompt is required');
});

test('generates an image, stores it on the public disk, and returns markdown', function () {
    $result = (string) (new GenerateImage)->handle(new Request([
        'prompt' => 'A rubber duck on a mechanical keyboard',
    ]));

    expect($result)->toStartWith('![A rubber duck on a mechanical keyboard](')
        ->and($result)->toContain('/storage/ai-images/')
        ->and($result)->toEndWith(')');

    expect(Storage::disk('public')->files('ai-images'))->toHaveCount(1);

    Image::assertGenerated(fn ($prompt) => $prompt->prompt === 'A rubber duck on a mechanical keyboard');
});

test('passes the aspect ratio through to the image generator', function () {
    (new GenerateImage)->handle(new Request([
        'prompt' => 'A sweeping landscape painting',
        'size' => '3:2',
    ]));

    Image::assertGenerated(fn ($prompt) => $prompt->size === '3:2');
});

test('ignores unsupported size values', function () {
    (new GenerateImage)->handle(new Request([
        'prompt' => 'A portrait study',
        'size' => '16:9',
    ]));

    Image::assertGenerated(fn ($prompt) => $prompt->size === null);
});

test('escapes brackets in the markdown alt text', function () {
    $result = (string) (new GenerateImage)->handle(new Request([
        'prompt' => 'A sign reading [OPEN]',
    ]));

    expect($result)->toStartWith('![A sign reading OPEN](');
});
