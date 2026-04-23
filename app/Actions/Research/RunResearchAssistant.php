<?php

namespace App\Actions\Research;

use App\Ai\Agents\AgentType;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\Plugins\Routers\AgentTypeRouter;
use App\Http\Controllers\ResearchController;
use App\Providers\KernelServiceProvider;

/**
 * Synchronous orchestrator for the multi-agent research pipeline. Used
 * by the {@see ResearchController} JSON endpoint. Pure adapter over the
 * {@see AgentKernel}: stamps `agent_type = Research` on the context so
 * the {@see AgentTypeRouter}
 * skips classification and dispatches the research pipeline directly,
 * then reshapes the kernel's envelope into the endpoint's historical
 * `{answer, research, critic, iterations, warnings}` payload.
 *
 * The merge-retry semantics (a Critic rejection triggers a surgical
 * follow-up Researcher pass + union of findings) come from the
 * `MergeResearchRetryStrategy` registered in
 * {@see KernelServiceProvider} for the `research_pipeline`
 * key — no special wiring needed here.
 */
class RunResearchAssistant
{
    public function __construct(
        private readonly AgentKernel $kernel,
    ) {}

    /**
     * @return array{
     *   answer: string,
     *   research: array<string, mixed>,
     *   critic: array<string, mixed>,
     *   iterations: int,
     *   warnings: list<string>
     * }
     */
    public function execute(string $query): array
    {
        $query = trim($query);

        $context = new KernelContext($query);
        $context->setAgentType(AgentType::Research);

        $run = $this->kernel->run($query, $context);

        $output = $run['output'];
        $critic = $run['critic'] ?? ['approved' => true, 'warnings' => []];

        return [
            'answer' => (string) ($output['response'] ?? ''),
            'research' => is_array($output['research'] ?? null) ? $output['research'] : $this->emptyResearch($query),
            'critic' => $this->criticForPayload($critic),
            'iterations' => $run['iterations'],
            'warnings' => array_values(array_unique(array_merge(
                $output['warnings'] ?? [],
                $critic['warnings'] ?? [],
            ))),
        ];
    }

    /**
     * @param  array<string, mixed>  $critic
     * @return array<string, mixed>
     */
    private function criticForPayload(array $critic): array
    {
        return [
            'approved' => (bool) ($critic['approved'] ?? false),
            'issues' => array_values(array_filter((array) ($critic['issues'] ?? []), 'is_string')),
            'missing_topics' => array_values(array_filter((array) ($critic['missing_topics'] ?? []), 'is_string')),
            'improvement_suggestions' => array_values(array_filter((array) ($critic['improvement_suggestions'] ?? []), 'is_string')),
            'confidence' => in_array($critic['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $critic['confidence'] : 'medium',
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
