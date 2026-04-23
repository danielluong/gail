<?php

namespace App\Ai\Workflow\Support;

use App\Ai\Agents\Research\ResearcherAgent;
use App\Ai\Workflow\Kernel\Plugins\Pipelines\ResearchPipelinePlugin;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

/**
 * Drives the {@see ResearcherAgent} as a stream so its tool calls show
 * up in the chat UI live, while the text deltas (which are strict JSON,
 * not user-visible) are accumulated internally for the downstream
 * Editor step.
 *
 * Used by {@see ResearchPipelinePlugin}'s streaming path so the
 * Researcher's tool-call frames flow live to the chat UI while the
 * Editor produces the visible markdown answer afterwards.
 *
 * Persisted tool-call entries carry `name`, `arguments`, and critically
 * `result_id` — the OpenAI gateway maps `result_id` to the Responses API
 * `call_id` that ties each function_call to its function_call_output.
 * The stream event's toArray() drops that id, so we read it off the
 * underlying Data\ToolCall / Data\ToolResult object on the event instance
 * instead.
 */
class ResearcherStreamer
{
    /**
     * Yields SSE-framed events for the Researcher's tool activity. The
     * accumulated strict-JSON reply, the tool-call/result records, and a
     * failure flag are written to the by-reference outs so the caller can
     * patch them onto the assistant row and drive the Editor downstream.
     *
     * @param  list<array{id: string, name: string, arguments: array<array-key, mixed>, result_id: ?string}>  $toolCalls
     * @param  list<array{id: string, name: string, arguments: array<array-key, mixed>, result: string, result_id: ?string}>  $toolResults
     */
    public function stream(
        string $query,
        ?string $model,
        string &$researchJson,
        array &$toolCalls,
        array &$toolResults,
        bool &$failed,
    ): Generator {
        $prompt = "Research question: {$query}\n\nRun your tools and return the JSON object described in your instructions.";
        $collected = '';

        /*
         * laravel/ai's tool_result stream event carries tool_name but
         * not the arguments that were passed in; we track both here
         * so the persisted tool_result row has the same shape
         * DatabaseConversationStore would have written for a
         * chat-agent turn (id, name, arguments, result, result_id).
         */
        $toolMeta = [];

        try {
            $stream = ResearcherAgent::make()->stream($prompt, model: $model);

            foreach ($stream as $event) {
                $payload = $event->toArray();
                $type = $payload['type'] ?? null;

                if ($type === 'text_delta') {
                    $collected .= (string) ($payload['delta'] ?? '');

                    continue;
                }

                if ($event instanceof ToolCall) {
                    $id = (string) $event->toolCall->id;
                    $name = (string) $event->toolCall->name;
                    $arguments = $event->toolCall->arguments;
                    $resultId = $event->toolCall->resultId;

                    $toolCalls[] = [
                        'id' => $id,
                        'name' => $name,
                        'arguments' => $arguments,
                        'result_id' => $resultId,
                    ];
                    $toolMeta[$id] = [
                        'name' => $name,
                        'arguments' => $arguments,
                        'result_id' => $resultId,
                    ];

                    yield "data: {$event}\n\n";

                    continue;
                }

                if ($event instanceof ToolResult) {
                    $id = (string) $event->toolResult->id;
                    $meta = $toolMeta[$id] ?? [
                        'name' => (string) $event->toolResult->name,
                        'arguments' => $event->toolResult->arguments,
                        'result_id' => null,
                    ];

                    /*
                     * Prefer the result's own resultId over the cached
                     * one from the tool_call — same value in practice,
                     * but the ToolResult is the source of truth for
                     * the OpenAI call_id the gateway will send.
                     */
                    $resultId = $event->toolResult->resultId ?? $meta['result_id'];

                    $toolResults[] = [
                        'id' => $id,
                        'name' => $meta['name'],
                        'arguments' => $meta['arguments'],
                        'result' => (string) ($payload['result'] ?? ''),
                        'result_id' => $resultId,
                    ];

                    yield "data: {$event}\n\n";
                }
            }
        } catch (Throwable $e) {
            Log::channel('ai')->warning('research.researcher_stream_failed', [
                'error' => $e->getMessage(),
            ]);

            /*
             * Still surface the failure as a toast — the phase chip
             * will flip to 'failed' once the caller yields it, but
             * the toast gives the user the actual error message.
             */
            yield 'data: '.json_encode([
                'type' => 'warning',
                'message' => 'Researcher failed: '.$e->getMessage().' — answering from prior knowledge.',
            ])."\n\n";

            $researchJson = '';
            $failed = true;

            return;
        }

        $researchJson = trim($collected);
        $failed = false;
    }
}
