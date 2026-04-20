<?php

use App\Support\ModelPricing;

beforeEach(function () {
    config()->set('pricing', [
        'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6],
    ]);
});

test('computes cost from prompt + completion tokens', function () {
    $cost = (new ModelPricing)->costFor('gpt-4o', [
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
    ]);

    // 1M * $2.5 + 0.5M * $10 = $2.5 + $5 = $7.50
    expect($cost)->toBe(7.5);
});

test('returns zero when both token counts are zero', function () {
    $cost = (new ModelPricing)->costFor('gpt-4o', [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($cost)->toBe(0.0);
});

test('returns null when the model is unknown', function () {
    $cost = (new ModelPricing)->costFor('llama-cheap:3b', [
        'prompt_tokens' => 1_000,
        'completion_tokens' => 1_000,
    ]);

    expect($cost)->toBeNull();
});

test('returns null when model is empty or null', function () {
    expect((new ModelPricing)->costFor(null, ['prompt_tokens' => 1]))->toBeNull();
    expect((new ModelPricing)->costFor('', ['prompt_tokens' => 1]))->toBeNull();
});

test('returns null when usage is null', function () {
    expect((new ModelPricing)->costFor('gpt-4o', null))->toBeNull();
});

test('tolerates missing keys in the usage array', function () {
    $cost = (new ModelPricing)->costFor('gpt-4o-mini', []);

    expect($cost)->toBe(0.0);
});
