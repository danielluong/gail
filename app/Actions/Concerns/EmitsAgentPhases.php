<?php

namespace App\Actions\Concerns;

use App\Actions\Research\StreamResearchResponse;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

/**
 * Shared phase-emission glue for multi-agent streaming actions.
 *
 * Every such action needs to (a) yield `phase` SSE frames for each
 * stage of the pipeline and (b) persist the final phase list onto
 * `meta.phases` on the assistant row so the chat UI's inline phase
 * strip still shows the sequence after a page refresh. This trait
 * centralises both concerns so a new workflow (e.g. a code-review
 * pipeline on top of {@see StreamResearchResponse})
 * gets the feature by composing the trait instead of copying ~30
 * lines of orchestration plumbing.
 *
 * Usage inside a streaming action:
 *
 *     use App\Actions\Concerns\EmitsAgentPhases;
 *
 *     class StreamMyWorkflow
 *     {
 *         use EmitsAgentPhases;
 *
 *         private function frames(...): Generator
 *         {
 *             $phases = [];
 *             yield $this->yieldPhase($phases, [
 *                 'key' => 'scanner',
 *                 'label' => 'Scanning',
 *                 'status' => 'running',
 *             ]);
 *             // ... run scanner ...
 *             yield $this->yieldPhase($phases, [
 *                 'key' => 'scanner',
 *                 'label' => 'Scanning',
 *                 'status' => 'complete',
 *             ]);
 *             // ... after the main agent stream finishes ...
 *             $this->persistPhases($conversationId, $pivot, $phases);
 *         }
 *     }
 */
trait EmitsAgentPhases
{
    /**
     * Emit a `phase` SSE frame AND append/update the phase entry in
     * the caller's accumulator by `key`. First event for a given key
     * appends; subsequent events with the same key merge in place so
     * a running → complete transition updates the chip rather than
     * duplicating it.
     *
     * Returns the fully-framed SSE string (including `data: ...\n\n`)
     * the caller yields from its generator.
     *
     * @param  list<array<string, mixed>>  $phases  accumulator, mutated by reference
     * @param  array{key: string, label: string, status: string}&array<string, mixed>  $phase
     */
    protected function yieldPhase(array &$phases, array $phase): string
    {
        $idx = array_search(
            $phase['key'],
            array_column($phases, 'key'),
            true,
        );

        if ($idx === false) {
            $phases[] = $phase;
        } else {
            $phases[$idx] = array_merge($phases[$idx], $phase);
        }

        return 'data: '.json_encode(['type' => 'phase'] + $phase)."\n\n";
    }

    /**
     * Merge the emitted phase list into `meta.phases` on the latest
     * assistant row since `$pivot`. Runs after laravel/ai's
     * persistence middleware has completed the row, so it's an update
     * rather than an insert.
     *
     * No-op when the phase list is empty — preserves the row's
     * existing `updated_at` instead of bumping it for a write that
     * wouldn't change anything.
     *
     * @param  list<array<string, mixed>>  $phases
     */
    protected function persistPhases(
        string $conversationId,
        Carbon $pivot,
        array $phases,
    ): void {
        if ($phases === []) {
            return;
        }

        $assistant = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $pivot)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($assistant === null) {
            return;
        }

        $meta = is_array($assistant->meta) ? $assistant->meta : [];
        $meta['phases'] = array_values($phases);
        $assistant->meta = $meta;
        $assistant->save();
    }

    /**
     * Merge a sibling worker agent's tool calls and results into the
     * persisted assistant row laravel/ai just wrote. The chat-UI-facing
     * agent in a multi-agent workflow is typically tool-free (a writer,
     * an editor, etc.) — the tool activity the user saw live came from
     * a different agent that ran inside the streaming action. Without
     * this patch the DB row's tool_calls / tool_results would be `[]`
     * and the tool badges would vanish on page refresh.
     *
     * Orphan entries are dropped before writing: OpenAI's chat API
     * rejects any assistant message whose tool_calls aren't matched
     * 1:1 by subsequent tool messages (HTTP 400 on the next prompt's
     * history replay). A worker that hits its MaxSteps cap — or a tool
     * whose result event never fires — can leave gaps in the 1:1
     * mapping, so we filter both sides by the intersection of ids
     * before persisting.
     *
     * @param  list<array{id: string, name: string, arguments: array<array-key, mixed>, result_id?: ?string}>  $toolCalls
     * @param  list<array{id: string, name: string, arguments: array<array-key, mixed>, result: string, result_id?: ?string}>  $toolResults
     */
    protected function persistSiblingToolActivity(
        string $conversationId,
        Carbon $pivot,
        array $toolCalls,
        array $toolResults,
    ): void {
        [$toolCalls, $toolResults] = $this->pairToolCallsWithResults($toolCalls, $toolResults);

        if ($toolCalls === [] && $toolResults === []) {
            return;
        }

        $assistant = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $pivot)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($assistant === null) {
            return;
        }

        $existingCalls = is_array($assistant->tool_calls) ? $assistant->tool_calls : [];
        $existingResults = is_array($assistant->tool_results) ? $assistant->tool_results : [];

        // Worker activity happens before the (empty) main-agent turn,
        // so prepend so the UI renders them in the order the user saw.
        $assistant->tool_calls = [...$toolCalls, ...$existingCalls];
        $assistant->tool_results = [...$toolResults, ...$existingResults];
        $assistant->save();
    }

    /**
     * Keep only tool_call / tool_result entries whose ids appear on
     * both sides. Shared by persistSiblingToolActivity; private so
     * callers don't depend on the pairing strategy directly.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $toolResults
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function pairToolCallsWithResults(array $toolCalls, array $toolResults): array
    {
        $callIds = array_column($toolCalls, 'id');
        $resultIds = array_column($toolResults, 'id');

        $pairedCalls = array_values(array_filter(
            $toolCalls,
            fn (array $call) => isset($call['id']) && in_array($call['id'], $resultIds, true),
        ));

        $pairedResults = array_values(array_filter(
            $toolResults,
            fn (array $result) => isset($result['id']) && in_array($result['id'], $callIds, true),
        ));

        return [$pairedCalls, $pairedResults];
    }
}
