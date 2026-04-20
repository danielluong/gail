<?php

use App\Ai\Contracts\DisplayableTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

/**
 * Every tool the chat agent can reach — the union of the universal
 * `ai.tools.core` tag (auto-included by BaseAgent) and the
 * chat-specific `ai.tools.chat` tag. Both sets must satisfy the
 * contract assertions below, so they share one iterator. Tools in
 * these tags are required by project convention to implement both
 * {@see Tool} and {@see DisplayableTool}; the first two tests in
 * this file are the regression gates that enforce that.
 *
 * @return list<Tool&DisplayableTool>
 */
function chatReachableTools(): array
{
    return [
        ...iterator_to_array(app()->tagged('ai.tools.core'), preserve_keys: false),
        ...iterator_to_array(app()->tagged('ai.tools.chat'), preserve_keys: false),
    ];
}

test('every tagged ai.tool implements the Tool contract', function () {
    $tools = chatReachableTools();

    expect($tools)->not->toBeEmpty();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});

test('every tagged ai.tool exposes a non-empty description', function () {
    foreach (chatReachableTools() as $tool) {
        $description = (string) $tool->description();

        expect($description)->not->toBe('', get_class($tool).' must provide a description')
            ->and(strlen($description))->toBeGreaterThanOrEqual(20, get_class($tool).' description is suspiciously short');
    }
});

test('every tagged ai.tool implements DisplayableTool with a non-empty label', function () {
    foreach (chatReachableTools() as $tool) {
        expect($tool)->toBeInstanceOf(DisplayableTool::class, get_class($tool).' must implement DisplayableTool');

        $label = $tool->label();

        expect($label)->toBeString()
            ->and($label)->not->toBe('', get_class($tool).' must provide a non-empty label');
    }
});

test('every tagged ai.tool returns a valid JSON schema array', function () {
    $jsonSchema = new JsonSchemaTypeFactory;

    foreach (chatReachableTools() as $tool) {
        $schema = $tool->schema($jsonSchema);

        expect($schema)->toBeArray(get_class($tool).' schema() must return an array');

        foreach ($schema as $key => $value) {
            expect($key)->toBeString()
                ->and($value)->toBeInstanceOf(Type::class, get_class($tool)."::schema() key [{$key}] must be a JSON schema Type");
        }
    }
});

test('every tagged ai.tool marks all schema properties as required (OpenAI strict mode)', function () {
    $jsonSchema = new JsonSchemaTypeFactory;

    foreach (chatReachableTools() as $tool) {
        $schema = $tool->schema($jsonSchema);

        if ($schema === []) {
            continue;
        }

        $serialized = (new ObjectSchema($schema))->toSchema();
        $properties = array_keys($serialized['properties'] ?? []);
        $required = $serialized['required'] ?? [];

        sort($properties);
        sort($required);

        expect($required)->toBe(
            $properties,
            get_class($tool).' must list every schema property in required() — OpenAI strict mode rejects partial required arrays. Mark optional fields ->required()->nullable().'
        );
    }
});
