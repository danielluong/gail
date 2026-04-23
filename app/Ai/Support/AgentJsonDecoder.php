<?php

namespace App\Ai\Support;

/**
 * Lenient JSON recovery for LLM replies that are *supposed* to be JSON
 * but sometimes aren't: fenced output (```json {...} ```), a token of
 * preamble before the object, a trailing apology, etc. Every
 * multi-agent workflow's strict-JSON stages (researcher, reviewer,
 * ExtractFactsTool, …) need the same fallback logic, so this lives
 * alongside the AI support layer and both actions and tools import it
 * directly instead of copy-pasting the 25-line helper.
 */
class AgentJsonDecoder
{
    /**
     * Try hard to recover a JSON object from a raw model reply.
     * Returns the decoded associative array, or null if no valid JSON
     * object can be found anywhere in the text.
     *
     * Recovery order:
     *   1. Trim and try json_decode directly.
     *   2. Strip a surrounding ```json code fence, retry.
     *   3. Slice the substring between the first `{` and the last `}`,
     *      retry. This handles "Sure, here is the JSON: {…} hope this
     *      helps." and similar model preamble/postamble.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $text = trim($raw);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $match)) {
            $text = $match[1];
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
