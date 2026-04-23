<?php

namespace App\Ai\Workflow\Contracts;

use Generator;

/**
 * A {@see Pipeline} that can also emit framed SSE events as it runs.
 *
 * The synchronous contract lives on {@see Pipeline::run()} and stays
 * pure array-in / array-out so orchestrators that don't care about
 * streaming (the JSON endpoint, unit tests) can treat every pipeline
 * uniformly. This interface is an optional capability on top — pipelines
 * that implement it allow chat-UI streaming actions to forward tool
 * calls, text deltas, and phase frames live while still returning the
 * same final context dict.
 *
 * Implementations yield already-framed SSE strings (`"data: …\n\n"`)
 * so callers can just `yield from` the generator without re-framing.
 * The final context dict (same shape as {@see Pipeline::run()}'s return)
 * is produced via a `return` inside the generator and read back with
 * `Generator::getReturn()` after the loop completes.
 */
interface StreamablePipeline extends Pipeline
{
    /**
     * Run the pipeline while yielding SSE frame strings. The final
     * context dict is returned via {@see Generator::getReturn()}.
     *
     * @param  array<string, mixed>  $input
     * @return Generator<int, string, mixed, array<string, mixed>>
     */
    public function stream(array $input): Generator;
}
