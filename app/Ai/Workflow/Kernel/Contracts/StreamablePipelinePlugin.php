<?php

namespace App\Ai\Workflow\Kernel\Contracts;

use App\Ai\Workflow\Kernel\KernelContext;
use Generator;

/**
 * Optional streaming capability on top of {@see PipelinePlugin}. The
 * synchronous contract on `execute()` stays pure array-in/array-out so
 * sync callers (the JSON endpoints, unit tests) can run any pipeline
 * uniformly. Streaming is a separate affordance — pipelines that can
 * forward live SSE frames (text_delta, tool_call) implement both.
 *
 * Implementations yield already-framed SSE strings (`"data: …\n\n"`)
 * so the Kernel can `yield from` the generator without re-framing.
 * The final context dict (same shape as `execute()`'s `result`) is
 * returned via `Generator::getReturn()`.
 */
interface StreamablePipelinePlugin extends PipelinePlugin
{
    /**
     * @param  array<string, mixed>  $input
     * @return Generator<int, string, mixed, array<string, mixed>>
     */
    public function stream(array $input, KernelContext $context): Generator;
}
