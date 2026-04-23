<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Agents\BaseAgent;
use App\Ai\Agents\Router\RouterAgent;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Routing\UniversalRouter;
use App\Enums\InputCategory;
use Closure;
use Generator;

/**
 * Chat-UI Router workflow: classify the user's intent, configure the
 * {@see RouterAgent} facade with the verdict, then stream the facade's
 * specialist response. Distinct from the JSON `agent_type_router`
 * dispatch, which routes among separate sync pipelines (research /
 * content / chat_specialist) — here the "specialist" is the same
 * facade with different prompt configuration.
 *
 * Sync `execute()` returns the classifier verdict only; there's no
 * standalone synchronous "router specialist run" today. Streaming is
 * the canonical path for this workflow.
 *
 * No critic by design: the router specialist's short answer is what
 * the user sees, and a regenerate button covers the retry case.
 */
final class RouterPipelinePlugin implements StreamablePipelinePlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
        private readonly UniversalRouter $router,
    ) {}

    public function getName(): string
    {
        return 'router_pipeline';
    }

    public function steps(): array
    {
        return ['classifier_step'];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $envelope = $this->kernel->dispatch('classifier_step', $input, $context);
        $classification = $envelope['result'];

        $rawCategory = InputCategory::tryFromString((string) ($classification['category'] ?? '')) ?? InputCategory::Chat;
        $confidence = (float) ($classification['confidence'] ?? 0.0);
        $routedCategory = $this->router->routeCategory($rawCategory, $confidence);

        return [
            'result' => [
                ...$input,
                'category' => $routedCategory->value,
                'confidence' => $confidence,
                'warnings' => array_values(array_unique(array_merge(
                    $input['warnings'] ?? [],
                    $classification['warnings'] ?? [],
                ))),
            ],
            'meta' => ['plugin' => $this->getName(), 'type' => 'pipeline'],
        ];
    }

    public function stream(array $input, KernelContext $context): Generator
    {
        $query = (string) ($input['query'] ?? '');
        $facade = $context->facade();
        $attachments = $context->attachments();
        $model = $context->modelOverride();
        $warnings = $input['warnings'] ?? [];
        $yieldPhase = $context->yieldPhase();

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'classifier',
                'label' => 'Classifying',
                'status' => 'running',
            ]);
        }

        $envelope = $this->kernel->dispatch('classifier_step', ['query' => $query], $context);
        $classification = $envelope['result'];

        $rawCategory = InputCategory::tryFromString((string) ($classification['category'] ?? '')) ?? InputCategory::Chat;
        $confidence = (float) ($classification['confidence'] ?? 0.0);
        $classifierWarning = $classification['warnings'][0] ?? null;
        $routedCategory = $this->router->routeCategory($rawCategory, $confidence);

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'classifier',
                'label' => 'Classifying',
                'status' => 'complete',
                'category' => $routedCategory->value,
                'confidence' => $confidence,
            ]);
        }

        if ($facade instanceof RouterAgent) {
            $facade
                ->withCategory($routedCategory)
                ->withConfidence($confidence)
                ->withClassifierWarning($classifierWarning);
        }

        $answerLabel = 'Answering as '.$routedCategory->value;
        $answer = '';

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'answer',
                'label' => $answerLabel,
                'status' => 'running',
            ]);
        }

        if ($facade instanceof BaseAgent) {
            foreach ($facade->stream($query, $attachments, model: $model) as $event) {
                $payload = $event->toArray();

                if (($payload['type'] ?? null) === 'text_delta') {
                    $answer .= (string) ($payload['delta'] ?? '');
                }

                yield "data: {$event}\n\n";

                if (connection_aborted()) {
                    break;
                }
            }
        }

        if ($yieldPhase instanceof Closure && ! connection_aborted()) {
            yield $yieldPhase([
                'key' => 'answer',
                'label' => $answerLabel,
                'status' => 'complete',
            ]);
        }

        return [
            ...$input,
            'query' => $query,
            'category' => $routedCategory->value,
            'confidence' => $confidence,
            'response' => trim($answer),
            'warnings' => array_values(array_unique(array_merge(
                $warnings,
                $classification['warnings'] ?? [],
            ))),
        ];
    }
}
