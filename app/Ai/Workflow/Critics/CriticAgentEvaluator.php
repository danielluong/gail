<?php

namespace App\Ai\Workflow\Critics;

use App\Ai\Agents\Research\CriticAgent;
use App\Ai\Support\JsonAgentCall;
use App\Ai\Workflow\Contracts\Critic;
use App\Ai\Workflow\Dto\CriticVerdict;

/**
 * Workflow-layer wrapper around the existing {@see CriticAgent} that
 * adapts its richer output (missing_topics + improvement_suggestions)
 * onto the {@see Critic} contract's {@see CriticVerdict} shape via
 * {@see CriticVerdict::fromRawAgentResponse()}.
 *
 * Default-to-approved-on-failure policy: if the critic call throws or
 * returns unparseable JSON, the verdict becomes `approved = true` with
 * a warning. The rationale is the same as everywhere else in this
 * system — a broken meta-agent must not block the user's answer.
 */
class CriticAgentEvaluator implements Critic
{
    /**
     * @param  array{query?: string, response?: string, research?: array<string, mixed>|null, ...}  $data
     */
    public function evaluate(array $data): CriticVerdict
    {
        $query = (string) ($data['query'] ?? '');
        $answer = (string) ($data['response'] ?? '');
        $research = $data['research'] ?? null;

        $prompt = $this->buildPrompt($query, $answer, $research);

        [$parsed, $warning] = JsonAgentCall::tryDecode(
            logTag: 'universal.critic_failed',
            humanLabel: 'Critic',
            call: fn () => CriticAgent::make()->prompt($prompt),
            logContext: ['query' => $query],
        );

        if ($parsed === null) {
            return CriticVerdict::approvedFallback(warnings: [$warning]);
        }

        return CriticVerdict::fromRawAgentResponse($parsed);
    }

    /**
     * @param  array<string, mixed>|null  $research
     */
    private function buildPrompt(string $query, string $answer, ?array $research): string
    {
        $researchBlock = is_array($research) && $research !== []
            ? "Research findings (JSON):\n".json_encode($research, JSON_UNESCAPED_SLASHES)."\n\n"
            : '';

        return <<<PROMPT
        User question:
        {$query}

        {$researchBlock}Answer to evaluate:
        {$answer}

        Return the JSON object described in your instructions.
        PROMPT;
    }
}
