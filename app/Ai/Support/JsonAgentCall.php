<?php

namespace App\Ai\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Small runner for "invoke a strict-JSON agent and recover politely".
 * Every multi-agent workflow with a verdict-style sub-agent (Classifier,
 * Critic, Researcher, ExtractFactsTool's LLM caller, …) shares the same
 * three-arm soft-fail shape: the LLM call itself can throw, the reply
 * might not be JSON even when it should be, and callers want a single
 * human-readable warning string they can surface to the end user.
 *
 * This helper pairs with {@see AgentJsonDecoder} — the decoder stays
 * pure (string → array), this class layers the transport concerns
 * (agent invocation + `Log` channel + warning synthesis) on top.
 *
 * Call sites keep ownership of what happens after parsing:
 * enum coercion, confidence clamping, default-payload construction,
 * pushing the warning onto whatever `$warnings` accumulator the
 * orchestrator threads around.
 */
final class JsonAgentCall
{
    /**
     * Invoke the closure, decode the reply, and normalise both failure
     * modes into a `[parsed, warning]` tuple. Logs to the `ai` channel
     * on thrown exceptions so operators can correlate upstream outages.
     *
     * On success returns `[$parsed, '']` so callers can type the warning
     * as a plain `string` without null-gymnastics — the failure messages
     * are only meaningful when `$parsed === null` anyway.
     *
     * @param  Closure(): AgentResponse  $call
     * @param  array<string, mixed>  $logContext  merged into the log payload alongside the error message
     * @return array{0: array<array-key, mixed>|null, 1: string}
     */
    public static function tryDecode(
        string $logTag,
        string $humanLabel,
        Closure $call,
        array $logContext = [],
    ): array {
        try {
            $response = $call();
        } catch (Throwable $e) {
            Log::channel('ai')->warning($logTag, $logContext + ['error' => $e->getMessage()]);

            return [null, $humanLabel.' call failed: '.$e->getMessage()];
        }

        $parsed = AgentJsonDecoder::decode($response->text);

        if ($parsed === null) {
            return [null, $humanLabel.' returned non-JSON output; defaulting.'];
        }

        return [$parsed, ''];
    }
}
