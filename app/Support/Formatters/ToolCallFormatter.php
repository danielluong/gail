<?php

namespace App\Support\Formatters;

/**
 * Merges the stored tool_calls + tool_results arrays into the flat shape the
 * chat UI uses during a live stream.
 */
class ToolCallFormatter
{
    /**
     * @param  iterable<int, array<string, mixed>>|null  $toolCalls
     * @param  iterable<int, array<string, mixed>>|null  $toolResults
     * @return list<array{tool_id: string, tool_name: string, arguments: array<string, mixed>, result: ?string}>
     */
    public function format(?iterable $toolCalls, ?iterable $toolResults): array
    {
        $resultsById = $this->indexResultsById($toolResults);

        return collect($toolCalls ?? [])
            ->map(fn (array $call): array => $this->formatOne($call, $resultsById))
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, array<string, mixed>>|null  $toolResults
     * @return array<string, array<string, mixed>>
     */
    private function indexResultsById(?iterable $toolResults): array
    {
        $index = [];

        foreach ($toolResults ?? [] as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $index[(string) $entry['id']] = $entry;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $call
     * @param  array<string, array<string, mixed>>  $resultsById
     * @return array{tool_id: string, tool_name: string, arguments: array<string, mixed>, result: ?string}
     */
    private function formatOne(array $call, array $resultsById): array
    {
        $id = (string) ($call['id'] ?? '');
        $arguments = $call['arguments'] ?? [];

        return [
            'tool_id' => $id,
            'tool_name' => (string) ($call['name'] ?? ''),
            'arguments' => is_array($arguments) ? $arguments : [],
            'result' => isset($resultsById[$id]['result'])
                ? (string) $resultsById[$id]['result']
                : null,
        ];
    }
}
