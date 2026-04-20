<?php

use App\Ai\Agents\TitlerAgent;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;

/*
 * Guardrails for the one-shot titling agent. Each attribute captures a
 * real product constraint: use the cheapest provider model, cap tokens
 * so a runaway reply can't inflate cost, keep sampling deterministic
 * enough that the same first turn yields a stable title, and enforce a
 * short timeout so title generation never blocks the chat stream from
 * completing cleanly.
 */

function titlerAttribute(string $attributeClass): ReflectionAttribute
{
    $attributes = (new ReflectionClass(TitlerAgent::class))
        ->getAttributes($attributeClass);

    expect($attributes)->toHaveCount(1, $attributeClass.' must be declared exactly once');

    return $attributes[0];
}

test('TitlerAgent requests the cheapest model for its provider', function () {
    expect((new ReflectionClass(TitlerAgent::class))
        ->getAttributes(UseCheapestModel::class))
        ->toHaveCount(1);
});

test('TitlerAgent caps generation at a short title budget', function () {
    expect(titlerAttribute(MaxTokens::class)->getArguments())->toBe([24]);
});

test('TitlerAgent runs with low sampling temperature', function () {
    expect(titlerAttribute(Temperature::class)->getArguments())->toBe([0.2]);
});

test('TitlerAgent enforces a 15 second timeout so a bad model never stalls a stream', function () {
    expect(titlerAttribute(Timeout::class)->getArguments())->toBe([15]);
});

test('TitlerAgent instructions forbid quotes, prefaces, and trailing punctuation', function () {
    $instructions = (string) (new TitlerAgent)->instructions();

    expect($instructions)
        ->toContain('short titles')
        ->toContain('no quotes')
        ->toContain('no trailing punctuation')
        ->toContain('no preface');
});
