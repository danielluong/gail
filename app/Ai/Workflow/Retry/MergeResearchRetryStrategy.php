<?php

namespace App\Ai\Workflow\Retry;

use App\Actions\Research\RunResearchAssistant;
use App\Actions\UniversalAssistant\RunUniversalAssistant;
use App\Ai\Workflow\Contracts\Agent;
use App\Ai\Workflow\Contracts\RetryStrategy;
use App\Ai\Workflow\Steps\EditorStep;
use App\Ai\Workflow\Steps\ResearcherStep;

/**
 * Research-specific retry strategy: on Critic rejection, do a targeted
 * follow-up Researcher pass (the step augments the prompt from
 * `critic_feedback`), union its findings with the first pass's so
 * nothing found earlier is lost, and re-run the Editor on the merged
 * research. The pipeline argument is ignored because the strategy
 * drives the individual steps directly — re-running the whole pipeline
 * would replace the first pass's findings entirely, which is exactly
 * the behaviour this strategy exists to avoid.
 *
 * Mirrors the hand-written merge behaviour previously inlined in
 * {@see RunResearchAssistant}, moved here so
 * {@see RunUniversalAssistant} can
 * inherit it via a per-path strategy map without knowing anything
 * about research.
 */
final class MergeResearchRetryStrategy implements RetryStrategy
{
    public function __construct(
        private readonly ResearcherStep $researcher,
        private readonly EditorStep $editor,
    ) {}

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $criticFeedback
     * @return array<string, mixed>
     */
    public function retry(Agent $pipeline, array $previous, array $criticFeedback): array
    {
        $query = (string) ($previous['query'] ?? '');
        $previousResearch = is_array($previous['research'] ?? null) ? $previous['research'] : [];
        $previousWarnings = $previous['warnings'] ?? [];

        $followup = $this->researcher->run([
            'query' => $query,
            'critic_feedback' => $criticFeedback,
            'warnings' => $previousWarnings,
        ]);

        $mergedResearch = $this->mergeResearch(
            $previousResearch,
            is_array($followup['research'] ?? null) ? $followup['research'] : [],
        );

        $edited = $this->editor->run([
            'query' => $query,
            'research' => $mergedResearch,
            'warnings' => $followup['warnings'] ?? $previousWarnings,
        ]);

        return [
            ...$edited,
            'query' => $query,
            'research' => $mergedResearch,
        ];
    }

    /**
     * Union two research passes by topic. Facts and sources are
     * deduplicated so the final payload stays tight.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    private function mergeResearch(array $first, array $second): array
    {
        $findings = [];

        foreach ([$first, $second] as $pass) {
            foreach (($pass['findings'] ?? []) as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $topic = (string) ($finding['topic'] ?? '');

                if ($topic === '') {
                    continue;
                }

                $existing = $findings[$topic] ?? ['topic' => $topic, 'facts' => [], 'sources' => []];
                $existing['facts'] = array_values(array_unique(array_merge(
                    $existing['facts'],
                    array_filter(($finding['facts'] ?? []), 'is_string'),
                )));
                $existing['sources'] = array_values(array_unique(array_merge(
                    $existing['sources'],
                    array_filter(($finding['sources'] ?? []), 'is_string'),
                )));

                $findings[$topic] = $existing;
            }
        }

        return [
            'query' => $first['query'] ?? ($second['query'] ?? ''),
            'subtopics' => array_values(array_unique(array_merge(
                array_filter(($first['subtopics'] ?? []), 'is_string'),
                array_filter(($second['subtopics'] ?? []), 'is_string'),
            ))),
            'findings' => array_values($findings),
            'conflicts' => array_values(array_unique(array_merge(
                array_filter(($first['conflicts'] ?? []), 'is_string'),
                array_filter(($second['conflicts'] ?? []), 'is_string'),
            ))),
        ];
    }
}
