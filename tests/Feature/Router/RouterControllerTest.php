<?php

use App\Ai\Agents\Router\ClassifierAgent;
use App\Ai\Agents\Router\QuestionAnswerAgent;

test('POST /route validates the input field', function () {
    $this->postJson(route('route.run'), [])->assertUnprocessable();
    $this->postJson(route('route.run'), ['input' => ''])->assertUnprocessable();
    $this->postJson(route('route.run'), ['input' => str_repeat('a', 4001)])->assertUnprocessable();
});

test('POST /route returns the classifier verdict and specialist response as JSON', function () {
    ClassifierAgent::fake([
        json_encode(['category' => 'question', 'confidence' => 0.92]),
    ]);
    QuestionAnswerAgent::fake(['Recursion: a function that calls itself.']);

    $response = $this->postJson(route('route.run'), [
        'input' => 'Explain what recursion is',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'category',
        'confidence',
        'agent',
        'response',
        'warnings',
    ]);

    expect($response->json('category'))->toBe('question');
    expect($response->json('confidence'))->toBe(0.92);
    expect($response->json('agent'))->toBe('QuestionAnswerAgent');
    expect($response->json('response'))->toContain('Recursion');
});
