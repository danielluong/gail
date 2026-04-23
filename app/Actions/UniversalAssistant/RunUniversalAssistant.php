<?php

namespace App\Actions\UniversalAssistant;

use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\Plugins\Routers\AgentTypeRouter;
use Illuminate\Support\Facades\Log;

/**
 * JSON entry point for the universal assistant. After the Kernel
 * migration this class is a pure adapter — the orchestration (Router →
 * Pipeline → Critic → maybe one retry) lives entirely in
 * {@see AgentKernel::run()}; this class only:
 *
 *   1. handles the empty-input short-circuit (skip the LLM call entirely),
 *   2. forwards the trimmed input through the kernel,
 *   3. reshapes the kernel's `{output, pipeline, critic, trace, iterations}`
 *      envelope into the endpoint's historical JSON payload.
 *
 * The classifier verdict is stashed by the
 * {@see AgentTypeRouter} on the
 * shared {@see KernelContext} (Mode B), so this adapter can read it back
 * for the `category` / `confidence` fields without re-classifying.
 */
class RunUniversalAssistant
{
    public function __construct(
        private readonly AgentKernel $kernel,
    ) {}

    /**
     * @return array{
     *   category: string,
     *   confidence: float,
     *   selected_path: string,
     *   response: string,
     *   research: array<string, mixed>,
     *   critic: array<string, mixed>,
     *   iterations: int,
     *   warnings: list<string>,
     * }
     */
    public function execute(string $input): array
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return [
                'category' => 'chat',
                'confidence' => 0.0,
                'selected_path' => 'chat',
                'response' => '',
                'research' => [],
                'critic' => $this->emptyCritic(),
                'iterations' => 0,
                'warnings' => ['Empty input; nothing to classify.'],
            ];
        }

        $context = new KernelContext($trimmed);
        $run = $this->kernel->run($trimmed, $context);

        $output = $run['output'];
        $critic = $run['critic'] ?? $this->emptyCritic();
        $classification = $context->classification() ?? [
            'category' => 'chat',
            'confidence' => 0.0,
            'warnings' => [],
        ];
        $selectedPath = $this->pathFor($run['pipeline']);

        Log::channel('ai')->info('universal.classified', [
            'input_preview' => mb_substr($trimmed, 0, 120),
            'classified_category' => $classification['category'] ?? 'chat',
            'confidence' => $classification['confidence'] ?? 0.0,
            'selected_path' => $selectedPath,
        ]);

        $warnings = array_values(array_unique(array_merge(
            $classification['warnings'] ?? [],
            $output['warnings'] ?? [],
            $critic['warnings'] ?? [],
        )));

        return [
            'category' => (string) ($classification['category'] ?? 'chat'),
            'confidence' => (float) ($classification['confidence'] ?? 0.0),
            'selected_path' => $selectedPath,
            'response' => (string) ($output['response'] ?? ''),
            'research' => is_array($output['research'] ?? null) ? $output['research'] : [],
            'critic' => $critic,
            'iterations' => $run['iterations'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Map the kernel's pipeline plugin name to the endpoint's historical
     * `selected_path` field. Anything that isn't research/content is
     * treated as the chat fallback — matches the prior
     * `agents[$path] ?? agents['chat']` collapse.
     */
    private function pathFor(string $pipeline): string
    {
        return match ($pipeline) {
            'research_pipeline' => 'research',
            'content_pipeline' => 'content',
            default => 'chat',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCritic(): array
    {
        return [
            'approved' => true,
            'issues' => [],
            'missing' => [],
            'confidence' => 'low',
            'warnings' => [],
        ];
    }
}
