<?php

namespace App\Actions\Router;

use App\Ai\Agents\Router\ChatAgent;
use App\Ai\Agents\Router\QuestionAnswerAgent;
use App\Ai\Agents\Router\TaskAgent;
use App\Ai\Workflow\Routing\UniversalRouter;
use App\Ai\Workflow\Steps\ClassifierStep;
use App\Enums\InputCategory;
use App\Http\Controllers\RouterController;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronous orchestrator for the router workflow. Used by the
 * {@see RouterController} JSON endpoint.
 *
 * Flow:
 *   1. {@see ClassifierStep}   → normalised {category, confidence} + warnings
 *   2. {@see UniversalRouter::routeCategory()} → routed category (low-confidence forces Chat)
 *   3. Selected specialist agent → plain text response
 *
 * This action dispatches directly to the three simple specialists
 * (QuestionAnswer, Task, Chat) rather than routing through the
 * UniversalAssistant's research/content pipelines. That's intentional:
 * the router "example" endpoint is the one-LLM-call-per-path reference
 * implementation, demonstrating the classifier + deterministic router
 * pattern without adding a Critic loop or multi-stage pipelines.
 *
 * Soft-fails at every LLM boundary (logs + best-effort fallback). The
 * JSON endpoint's response shape is contract, so callers can rely on
 * the keys being present even when upstream LLM calls misbehave.
 */
class RunRouterExample
{
    public function __construct(
        private readonly ClassifierStep $classifier,
        private readonly UniversalRouter $router,
    ) {}

    /**
     * @return array{
     *   category: string,
     *   confidence: float,
     *   agent: string,
     *   response: string,
     *   warnings: list<string>
     * }
     */
    public function execute(string $input): array
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return [
                'category' => InputCategory::Chat->value,
                'confidence' => 0.0,
                'agent' => class_basename(ChatAgent::class),
                'response' => '',
                'warnings' => ['Empty input; nothing to classify.'],
            ];
        }

        $classification = $this->classifier->run(['query' => $trimmed]);
        $warnings = $classification['warnings'] ?? [];

        // InputCategory::tryFromString can't return null here because
        // ClassifierStep always emits one of the enum's backing values
        // — but we fall back to Chat defensively so the match below
        // stays total.
        $rawCategory = InputCategory::tryFromString($classification['category']) ?? InputCategory::Chat;
        $confidence = (float) ($classification['confidence'] ?? 0.0);

        $routed = $this->router->routeCategory($rawCategory, $confidence);
        [$response, $agentName] = $this->runSpecialist($routed, $trimmed, $warnings);

        Log::channel('ai')->info('router.classified', [
            'input_preview' => mb_substr($trimmed, 0, 120),
            'classified_category' => $rawCategory->value,
            'confidence' => $confidence,
            'routed_category' => $routed->value,
            'agent' => $agentName,
        ]);

        return [
            'category' => $routed->value,
            'confidence' => $confidence,
            'agent' => $agentName,
            'response' => $response,
            'warnings' => $warnings,
        ];
    }

    /**
     * Dispatch to the concrete specialist. We hard-match on the enum
     * (rather than threading a `class-string<Agent>` around) because
     * `Agent::make()` lives on the Promptable trait, not the Agent
     * interface itself — PHPStan can't see it through a variable
     * class-string, but it can through the concrete class references
     * the match branches below resolve to.
     *
     * @param  list<string>  $warnings
     * @return array{0: string, 1: string} [response text, agent short-name]
     */
    private function runSpecialist(InputCategory $category, string $input, array &$warnings): array
    {
        try {
            [$response, $agentName] = match ($category) {
                InputCategory::Question => [QuestionAnswerAgent::make()->prompt($input), 'QuestionAnswerAgent'],
                InputCategory::Task => [TaskAgent::make()->prompt($input), 'TaskAgent'],
                InputCategory::Chat => [ChatAgent::make()->prompt($input), 'ChatAgent'],
            };
        } catch (Throwable $e) {
            $fallbackName = match ($category) {
                InputCategory::Question => 'QuestionAnswerAgent',
                InputCategory::Task => 'TaskAgent',
                InputCategory::Chat => 'ChatAgent',
            };

            Log::channel('ai')->warning('router.specialist_failed', [
                'agent' => $fallbackName,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Specialist ('.$fallbackName.') failed: '.$e->getMessage();

            return ['', $fallbackName];
        }

        return [trim($response->text), $agentName];
    }
}
