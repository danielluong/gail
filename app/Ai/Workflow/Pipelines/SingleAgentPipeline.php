<?php

namespace App\Ai\Workflow\Pipelines;

use App\Actions\Concerns\StreamsMultiAgentWorkflow;
use App\Ai\Agents\BaseAgent;
use App\Ai\Workflow\Contracts\StreamablePipeline;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\SingleAgentPipelinePlugin;
use Generator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps a single LLM-level {@see BaseAgent} so that it satisfies the
 * workflow-layer {@see StreamablePipeline} contract — a 1-step pipeline
 * is just as valid as a 5-step one from the orchestrator's point of
 * view. Used by {@see SingleAgentPipelinePlugin}
 * to lift a per-request facade agent into the Kernel runtime so Chat /
 * Limerick / MySQL flow through the same dispatch as multi-agent
 * workflows like Research and Router.
 *
 * Input dict shape:
 *
 *   'query'       string         required
 *   'attachments' list<Image>    optional; forwarded to $agent->stream()
 *   'model'       ?string        optional; overrides the agent's default
 *
 * Output dict shape:
 *
 *   'query'       string
 *   'response'    string         (accumulated text_delta for streaming,
 *                                  or $response->text for synchronous)
 *   'warnings'    list<string>
 *
 * Soft-fails to an empty response + warning on any underlying error so
 * the surrounding orchestrator / SSE bootstrap never 500s the request.
 */
final class SingleAgentPipeline implements StreamablePipeline
{
    public function __construct(
        private readonly BaseAgent $agent,
    ) {}

    /**
     * @param  array{query?: string, ...}  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $warnings = $input['warnings'] ?? [];

        try {
            $response = $this->agent->prompt($query);
            $text = trim((string) $response->text);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('single_agent_pipeline.run_failed', [
                'agent' => $this->agent::class,
                'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Agent failed: '.$e->getMessage();
            $text = '';
        }

        return [
            ...$input,
            'query' => $query,
            'response' => $text,
            'warnings' => $warnings,
        ];
    }

    /**
     * Exceptions propagate so the calling streaming trait
     * ({@see StreamsMultiAgentWorkflow}) can
     * emit the shared `error` SSE frame and terminate the stream
     * with `[DONE]`. Soft-fail is deliberately run()-only.
     *
     * @param  array{query?: string, attachments?: list<mixed>, model?: ?string, ...}  $input
     * @return Generator<int, string, mixed, array<string, mixed>>
     */
    public function stream(array $input): Generator
    {
        $query = (string) ($input['query'] ?? '');
        $attachments = (array) ($input['attachments'] ?? []);
        $model = $input['model'] ?? null;
        $warnings = $input['warnings'] ?? [];

        $text = '';

        foreach ($this->agent->stream($query, $attachments, model: $model) as $event) {
            $payload = $event->toArray();

            if (($payload['type'] ?? null) === 'text_delta') {
                $text .= (string) ($payload['delta'] ?? '');
            }

            yield "data: {$event}\n\n";

            if (connection_aborted()) {
                break;
            }
        }

        return [
            ...$input,
            'query' => $query,
            'response' => trim($text),
            'warnings' => $warnings,
        ];
    }

    public function agent(): BaseAgent
    {
        return $this->agent;
    }
}
