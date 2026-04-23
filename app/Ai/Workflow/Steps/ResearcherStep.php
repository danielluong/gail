<?php

namespace App\Ai\Workflow\Steps;

use App\Ai\Agents\Research\ResearcherAgent;
use App\Ai\Support\JsonAgentCall;
use App\Ai\Workflow\Contracts\Agent;

/**
 * Workflow-layer adapter for {@see ResearcherAgent}. Formats the query
 * (augmenting it with Critic feedback on retry passes), invokes the
 * researcher, and normalises its strict-JSON reply into the shape
 * downstream steps and the final response payload expect.
 *
 * Soft-fail: on parse failure or thrown exception, returns an empty
 * research payload + a warning so the Editor downstream still has
 * something to work with.
 */
class ResearcherStep implements Agent
{
    /**
     * @param  array{query?: string, critic_feedback?: array<string, mixed>, warnings?: list<string>}  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $warnings = $input['warnings'] ?? [];
        $augmented = $this->augmentQuery($query, $input['critic_feedback'] ?? null);

        $prompt = "Research question: {$augmented}\n\nRun your tools and return the JSON object described in your instructions.";

        [$parsed, $warning] = JsonAgentCall::tryDecode(
            logTag: 'universal.researcher_failed',
            humanLabel: 'Researcher',
            call: fn () => ResearcherAgent::make()->prompt($prompt),
            logContext: ['query' => $query],
        );

        if ($parsed === null) {
            $warnings[] = $warning;

            return [
                ...$input,
                'research' => $this->emptyResearch($query),
                'warnings' => $warnings,
            ];
        }

        return [
            ...$input,
            'research' => $this->normaliseResearch($parsed, $query),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $feedback
     */
    private function augmentQuery(string $original, ?array $feedback): string
    {
        if ($feedback === null) {
            return $original;
        }

        $missing = is_array($feedback['missing'] ?? null) ? $feedback['missing'] : [];
        $issues = is_array($feedback['issues'] ?? null) ? $feedback['issues'] : [];

        $extras = array_values(array_filter(
            array_merge($missing, $issues),
            fn ($v) => is_string($v) && trim($v) !== '',
        ));

        if ($extras === []) {
            return $original;
        }

        $bullets = implode("\n- ", $extras);

        return "{$original}\n\nAlso investigate these specific gaps flagged by review:\n- {$bullets}";
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseResearch(array $raw, string $query): array
    {
        return [
            'query' => (string) ($raw['query'] ?? $query),
            'subtopics' => array_values(array_filter((array) ($raw['subtopics'] ?? []), 'is_string')),
            'findings' => array_values(array_filter((array) ($raw['findings'] ?? []), 'is_array')),
            'conflicts' => array_values(array_filter((array) ($raw['conflicts'] ?? []), 'is_string')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResearch(string $query): array
    {
        return [
            'query' => $query,
            'subtopics' => [],
            'findings' => [],
            'conflicts' => [],
        ];
    }
}
