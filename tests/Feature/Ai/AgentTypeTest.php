<?php

use App\Ai\Agents\AgentType;
use App\Ai\Agents\ChatAgent;
use App\Ai\Agents\LimerickAgent;
use App\Ai\Agents\Research\ResearchAgent;

test('all agent types have a label', function () {
    foreach (AgentType::cases() as $type) {
        expect($type->label())->toBeString()->not->toBeEmpty();
    }
});

test('all agent types map to a valid agent class', function () {
    foreach (AgentType::cases() as $type) {
        expect(class_exists($type->agentClass()))->toBeTrue();
    }
});

test('options returns all cases with key and label', function () {
    $options = AgentType::options();

    expect($options)->toBeArray()
        ->and(count($options))->toBe(count(AgentType::cases()));

    foreach ($options as $option) {
        expect($option)->toHaveKeys(['key', 'label']);
    }
});

test('limerick agent type is registered', function () {
    expect(AgentType::Limerick->value)->toBe('limerick')
        ->and(AgentType::Limerick->label())->toBe('Limerick Mode')
        ->and(AgentType::Limerick->agentClass())->toBe(LimerickAgent::class);
});

test('agent type enum contains expected cases', function () {
    $values = array_map(fn ($c) => $c->value, AgentType::cases());

    expect($values)->toContain('default', 'limerick');
});

test('agent class mappings are correct', function () {
    expect(AgentType::Default->agentClass())->toBe(ChatAgent::class)
        ->and(AgentType::Research->agentClass())->toBe(ResearchAgent::class)
        ->and(AgentType::Limerick->agentClass())->toBe(LimerickAgent::class);
});

test('pipelinePluginName routes multi-agent workflows explicitly and chats to the single-agent default', function () {
    expect(AgentType::Research->pipelinePluginName())->toBe('research_pipeline')
        ->and(AgentType::Router->pipelinePluginName())->toBe('router_pipeline')
        ->and(AgentType::Default->pipelinePluginName())->toBe('single_agent_pipeline')
        ->and(AgentType::Limerick->pipelinePluginName())->toBe('single_agent_pipeline')
        ->and(AgentType::MySQLDatabase->pipelinePluginName())->toBe('single_agent_pipeline');
});
