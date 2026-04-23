<?php

use App\Ai\Agents\AgentType;
use App\Ai\Agents\ChatAgent;
use App\Ai\Workflow\Dto\CriticVerdict;
use App\Ai\Workflow\Kernel\KernelContext;

test('exposes the original input as a readonly identity', function () {
    $context = new KernelContext('hello world');

    expect($context->originalInput)->toBe('hello world');
    expect($context->retryCount)->toBe(0);
    expect($context->selectedPipeline)->toBeNull();
    expect($context->trace)->toBe([]);
});

test('untyped metadata accessors round-trip arbitrary values', function () {
    $context = new KernelContext('q');

    $context->set('custom_key', 'some value');
    $context->set('flag', null);

    expect($context->get('custom_key'))->toBe('some value');
    expect($context->get('missing', 'default'))->toBe('default');
    expect($context->has('flag'))->toBeTrue();
    expect($context->has('missing'))->toBeFalse();
});

test('recordTrace appends an entry per dispatch', function () {
    $context = new KernelContext('q');

    $context->recordTrace('chat_pipeline', 'pipeline', 12.5);
    $context->recordTrace('default_critic', 'critic', 4.0);

    expect($context->trace)->toHaveCount(2);
    expect($context->trace[0])->toBe([
        'plugin' => 'chat_pipeline',
        'type' => 'pipeline',
        'duration_ms' => 12.5,
    ]);
});

test('typed agentType accessor stores and returns the enum', function () {
    $context = new KernelContext('q');

    $context->setAgentType(AgentType::Research);

    expect($context->agentType())->toBe(AgentType::Research);
});

test('agentType coerces a raw string stashed via set() back to the enum', function () {
    $context = new KernelContext('q');

    // Simulates legacy callers (or tests) that wrote a string value
    // directly; the accessor still produces a typed read.
    $context->set(KernelContext::KEY_AGENT_TYPE, AgentType::Research->value);

    expect($context->agentType())->toBe(AgentType::Research);
});

test('agentType returns null when an unknown string is stashed', function () {
    $context = new KernelContext('q');

    $context->set(KernelContext::KEY_AGENT_TYPE, 'not-a-real-agent');

    expect($context->agentType())->toBeNull();
});

test('facade accessor accepts a BaseAgent and rejects anything else at read time', function () {
    $context = new KernelContext('q');
    $agent = ChatAgent::make();

    $context->setFacade($agent);

    expect($context->facade())->toBe($agent);

    // Corrupted raw set — read must return null rather than leaking the
    // wrong type to the consumer.
    $context->set(KernelContext::KEY_FACADE, new stdClass);
    expect($context->facade())->toBeNull();
});

test('yieldPhase accessor stores a Closure and ignores non-Closures on read', function () {
    $context = new KernelContext('q');
    $emit = fn (array $phase): string => json_encode($phase);

    $context->setYieldPhase($emit);
    expect($context->yieldPhase())->toBe($emit);

    $context->set(KernelContext::KEY_YIELD_PHASE, 'not a closure');
    expect($context->yieldPhase())->toBeNull();
});

test('attachments accessor defaults to an empty list when nothing is stashed', function () {
    $context = new KernelContext('q');

    expect($context->attachments())->toBe([]);

    $context->setAttachments([['name' => 'file.pdf']]);
    expect($context->attachments())->toBe([['name' => 'file.pdf']]);
});

test('modelOverride accessor returns null unless a string is set', function () {
    $context = new KernelContext('q');

    expect($context->modelOverride())->toBeNull();

    $context->setModelOverride('gpt-4o');
    expect($context->modelOverride())->toBe('gpt-4o');

    $context->setModelOverride(null);
    expect($context->modelOverride())->toBeNull();
});

test('classification accessor round-trips the classifier verdict', function () {
    $context = new KernelContext('q');

    $context->setClassification(['category' => 'task', 'confidence' => 0.9]);

    expect($context->classification())->toBe([
        'category' => 'task',
        'confidence' => 0.9,
    ]);
});

test('criticFeedback serializes the DTO on write and rehydrates on read', function () {
    $context = new KernelContext('q');
    $verdict = new CriticVerdict(
        approved: false,
        issues: ['i1'],
        missing: ['m1'],
        missingTopics: ['m1'],
        improvementSuggestions: [],
        confidence: 'medium',
        warnings: [],
    );

    $context->setCriticFeedback($verdict);

    // Stored as an array so step plugins can read
    // `critic_feedback.missing` without learning the DTO.
    expect($context->get(KernelContext::KEY_CRITIC_FEEDBACK))->toBeArray();

    // But typed readers get a DTO back via the accessor.
    $hydrated = $context->criticFeedback();
    expect($hydrated)->toBeInstanceOf(CriticVerdict::class);
    expect($hydrated->approved)->toBeFalse();
    expect($hydrated->missing)->toBe(['m1']);
});
