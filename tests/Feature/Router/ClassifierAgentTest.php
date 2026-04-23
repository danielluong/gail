<?php

use App\Ai\Agents\Router\ClassifierAgent;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;

function classifierAttribute(string $attributeClass): ReflectionAttribute
{
    $attributes = (new ReflectionClass(ClassifierAgent::class))
        ->getAttributes($attributeClass);

    expect($attributes)->toHaveCount(1, $attributeClass.' must be declared exactly once');

    return $attributes[0];
}

test('ClassifierAgent uses the provider cheapest model', function () {
    expect((new ReflectionClass(ClassifierAgent::class))
        ->getAttributes(UseCheapestModel::class))
        ->toHaveCount(1);
});

test('ClassifierAgent caps output at a short classification budget', function () {
    expect(classifierAttribute(MaxTokens::class)->getArguments())->toBe([128]);
});

test('ClassifierAgent runs with low sampling temperature', function () {
    expect(classifierAttribute(Temperature::class)->getArguments())->toBe([0.1]);
});

test('ClassifierAgent enforces a short timeout so a bad model never stalls the pipeline', function () {
    expect(classifierAttribute(Timeout::class)->getArguments())->toBe([15]);
});

test('ClassifierAgent instructions mandate strict JSON output and list all three categories', function () {
    $instructions = (string) (new ClassifierAgent)->instructions();

    expect($instructions)
        ->toContain('classifier')
        ->toContain('question')
        ->toContain('task')
        ->toContain('chat')
        ->toContain('Return ONLY valid JSON')
        ->toContain('category')
        ->toContain('confidence');
});
