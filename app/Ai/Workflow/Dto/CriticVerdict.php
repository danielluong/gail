<?php

namespace App\Ai\Workflow\Dto;

use App\Ai\Workflow\Contracts\Critic;
use App\Ai\Workflow\Critics\CriticAgentEvaluator;
use App\Ai\Workflow\Retry\MergeResearchRetryStrategy;

/**
 * A critic's structured judgement on a pipeline's output. Replaces the
 * prior `array{approved, issues, missing, ...}` shape on the
 * {@see Critic} contract (and its kernel-side adapter).
 *
 * The kernel's retry loop branches on {@see $approved}; the chat UI's
 * phase chip renders {@see $confidence} + {@see $issues} +
 * {@see $missingTopics}; {@see MergeResearchRetryStrategy} and the
 * step-level `augmentQuery()` helpers consume {@see $missing} and
 * {@see $issues} to build the follow-up prompt.
 *
 * Stored on `KernelContext::$metadata['critic_feedback']` and surfaced
 * in JSON envelopes via {@see toArray()} so downstream consumers that
 * have not migrated to the DTO (HTTP responses, step `critic_feedback`
 * reads) still work without rewrites.
 */
final readonly class CriticVerdict
{
    /**
     * @param  list<string>  $issues
     * @param  list<string>  $missing
     * @param  list<string>  $missingTopics
     * @param  list<string>  $improvementSuggestions
     * @param  'low'|'medium'|'high'  $confidence
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $approved,
        public array $issues,
        public array $missing,
        public array $missingTopics,
        public array $improvementSuggestions,
        public string $confidence,
        public array $warnings,
    ) {}

    /**
     * Default-to-approved fallback for use when a critic call throws
     * or returns unparseable JSON. Matches the policy documented on
     * {@see CriticAgentEvaluator}: a broken
     * meta-agent must not block the user's answer.
     *
     * @param  list<string>  $warnings
     */
    public static function approvedFallback(array $warnings = []): self
    {
        return new self(
            approved: true,
            issues: [],
            missing: [],
            missingTopics: [],
            improvementSuggestions: [],
            confidence: 'low',
            warnings: $warnings,
        );
    }

    /**
     * Normalise a raw LLM response into the canonical verdict shape.
     * Folds the critic's richer `missing_topics` +
     * `improvement_suggestions` lists into the unified `missing` list
     * that retry strategies consume, while preserving the split for
     * UI and telemetry consumers that want either view individually.
     *
     * Unknown confidence values coerce to `medium` rather than throwing
     * so a slightly off-contract LLM reply still produces a usable
     * verdict.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromRawAgentResponse(array $raw): self
    {
        $missingTopics = self::stringList($raw['missing_topics'] ?? []);
        $improvementSuggestions = self::stringList($raw['improvement_suggestions'] ?? []);
        $missing = array_values(array_filter(
            array_merge($missingTopics, $improvementSuggestions, self::stringList($raw['missing'] ?? [])),
            fn (string $v): bool => trim($v) !== '',
        ));

        $confidence = $raw['confidence'] ?? 'medium';
        $confidence = in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : 'medium';

        return new self(
            approved: (bool) ($raw['approved'] ?? false),
            issues: self::stringList($raw['issues'] ?? []),
            missing: $missing,
            missingTopics: $missingTopics,
            improvementSuggestions: $improvementSuggestions,
            confidence: $confidence,
            warnings: self::stringList($raw['warnings'] ?? []),
        );
    }

    /**
     * Rehydrate a previously-serialized verdict (e.g. from
     * `KernelContext::$metadata['critic_feedback']`). Unlike
     * {@see fromRawAgentResponse()} this does NOT re-merge
     * `missing_topics` + `improvement_suggestions` into `missing` —
     * the stored array already reflects the flattened union produced
     * on the first pass.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $confidence = $data['confidence'] ?? 'medium';
        $confidence = in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : 'medium';

        return new self(
            approved: (bool) ($data['approved'] ?? false),
            issues: self::stringList($data['issues'] ?? []),
            missing: self::stringList($data['missing'] ?? []),
            missingTopics: self::stringList($data['missing_topics'] ?? []),
            improvementSuggestions: self::stringList($data['improvement_suggestions'] ?? []),
            confidence: $confidence,
            warnings: self::stringList($data['warnings'] ?? []),
        );
    }

    /**
     * @return array{
     *   approved: bool,
     *   issues: list<string>,
     *   missing: list<string>,
     *   missing_topics: list<string>,
     *   improvement_suggestions: list<string>,
     *   confidence: 'low'|'medium'|'high',
     *   warnings: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'issues' => $this->issues,
            'missing' => $this->missing,
            'missing_topics' => $this->missingTopics,
            'improvement_suggestions' => $this->improvementSuggestions,
            'confidence' => $this->confidence,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_string'));
    }
}
