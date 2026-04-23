<?php

namespace App\Ai\Workflow\Kernel\Plugins\Pipelines;

use App\Ai\Agents\BaseAgent;
use App\Ai\Agents\Research\ResearchAgent;
use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\Contracts\StreamablePipelinePlugin;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Steps\EditorStep;
use App\Ai\Workflow\Support\ResearcherStreamer;
use Closure;
use Generator;

/**
 * Factual-question path. Researcher gathers findings with tools (live
 * tool-call frames forwarded), Editor produces the markdown answer.
 *
 * **Sync** runs Researcher → Editor through the Kernel so each step is
 * registered in the trace and reachable for retry strategies.
 *
 * **Streaming** is bespoke for two reasons that don't generalise:
 * 1. {@see ResearcherStreamer} has to drive the LLM-level Researcher
 *    agent's `stream()` directly so tool-call frames forward live.
 * 2. The Editor output is produced by the chat-UI facade
 *    ({@see ResearchAgent}) so its `RemembersConversations` trait
 *    persists the assistant row — switching the visible writer to the
 *    plain {@see EditorStep} loses persistence + UI styling.
 *
 * Phase frames (`researcher` → `editor`) are emitted via an optional
 * `yieldPhase` closure read from the {@see KernelContext}.
 */
final class ResearchPipelinePlugin implements StreamablePipelinePlugin
{
    public function __construct(
        private readonly AgentKernel $kernel,
        private readonly ResearcherStreamer $researcherStreamer,
        private readonly EditorStep $editor,
    ) {}

    public function getName(): string
    {
        return 'research_pipeline';
    }

    public function steps(): array
    {
        return ['researcher_step', 'editor_step'];
    }

    public function execute(array $input, KernelContext $context): array
    {
        $threaded = $input;

        foreach ($this->steps() as $stepName) {
            $envelope = $this->kernel->dispatch($stepName, $threaded, $context);
            $threaded = [...$threaded, ...$envelope['result']];
        }

        return [
            'result' => $threaded,
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

        $researchJson = '';
        $toolCalls = [];
        $toolResults = [];
        $researcherFailed = false;

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'researcher',
                'label' => 'Researching',
                'status' => 'running',
            ]);
        }

        foreach ($this->researcherStreamer->stream(
            $query,
            $model,
            $researchJson,
            $toolCalls,
            $toolResults,
            $researcherFailed,
        ) as $frame) {
            yield $frame;

            if (connection_aborted()) {
                return $this->earlyReturn($input, $query, $researchJson, $toolCalls, $toolResults, $researcherFailed, $warnings, '');
            }
        }

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'researcher',
                'label' => 'Researching',
                'status' => $researcherFailed ? 'failed' : 'complete',
            ]);
        }

        $research = $this->decodeResearch($researchJson);
        $answer = '';

        if ($yieldPhase instanceof Closure) {
            yield $yieldPhase([
                'key' => 'editor',
                'label' => 'Editing',
                'status' => 'running',
            ]);
        }

        if ($facade instanceof BaseAgent) {
            if ($facade instanceof ResearchAgent) {
                $facade->withResearch($researchJson !== '' ? $researchJson : null);
            }

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
        } else {
            $edited = $this->editor->run([
                'query' => $query,
                'research' => $research,
                'warnings' => $warnings,
            ]);
            $answer = (string) ($edited['response'] ?? '');
            $warnings = $edited['warnings'] ?? $warnings;
        }

        if ($yieldPhase instanceof Closure && ! connection_aborted()) {
            yield $yieldPhase([
                'key' => 'editor',
                'label' => 'Editing',
                'status' => 'complete',
            ]);
        }

        return [
            ...$input,
            'query' => $query,
            'research' => $research,
            'research_json' => $researchJson,
            'response' => trim($answer),
            'tool_calls' => $toolCalls,
            'tool_results' => $toolResults,
            'researcher_failed' => $researcherFailed,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResearch(string $researchJson): array
    {
        if ($researchJson === '') {
            return [];
        }

        $decoded = json_decode($researchJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $toolResults
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function earlyReturn(
        array $input,
        string $query,
        string $researchJson,
        array $toolCalls,
        array $toolResults,
        bool $researcherFailed,
        array $warnings,
        string $answer,
    ): array {
        return [
            ...$input,
            'query' => $query,
            'research' => $this->decodeResearch($researchJson),
            'research_json' => $researchJson,
            'response' => $answer,
            'tool_calls' => $toolCalls,
            'tool_results' => $toolResults,
            'researcher_failed' => $researcherFailed,
            'warnings' => $warnings,
        ];
    }
}
