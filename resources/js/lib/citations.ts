import type { ToolCall } from '@/types/chat';

/*
 * Models are told (via the web_search tool description) to cite search
 * results using bracket notation like [1] or [2] matching the numbered
 * list in the tool result. These helpers turn those bare numeric markers
 * into clickable links pointing at the original source.
 *
 * The source-of-truth for the URL → number mapping is the plain-text
 * rendering of the web-search result itself — parsing it here keeps the
 * backend output format unchanged and avoids a separate sidecar payload.
 */

export type Citation = { url: string; title: string };

/*
 * The WebSearch tool formats each result as:
 *   1. Title
 *      https://example.com
 *      optional snippet
 *
 * Extract every (number, url, title) triple. If the assistant cites a
 * number that isn't present we leave the text alone rather than produce
 * a broken link.
 */
const RESULT_PATTERN = /^(\d+)\.\s+(.+?)\n\s+(https?:\/\/\S+)/gm;

/*
 * Markdown already renders `[text](url)` as a link, so we only need to
 * rewrite bare numeric markers. Negative lookahead for `(` skips markers
 * that the model already formatted as links. Negative lookbehind for `!`
 * preserves markdown images `![alt](url)`. Comma-separated lists like
 * `[2, 3]` are captured as a single group and split on rewrite.
 */
const BARE_CITATION_PATTERN = /(?<!!)\[(\d+(?:\s*,\s*\d+)*)\](?!\()/g;

export function extractCitations(
    toolCalls: ToolCall[] | undefined,
): Map<number, Citation> {
    const citations = new Map<number, Citation>();

    if (!toolCalls) {
        return citations;
    }

    /*
     * When multiple web searches run in a single turn, the model's later
     * `[N]` references typically point at the most recent result set,
     * so later tool calls overwrite earlier ones for the same number.
     */
    for (const call of toolCalls) {
        if (call.tool_name !== 'WebSearch' || !call.result) {
            continue;
        }

        for (const match of call.result.matchAll(RESULT_PATTERN)) {
            const n = Number.parseInt(match[1], 10);

            if (Number.isFinite(n)) {
                citations.set(n, {
                    title: match[2].trim(),
                    url: match[3].trim(),
                });
            }
        }
    }

    return citations;
}

export function linkifyCitations(
    content: string,
    citations: Map<number, Citation>,
): string {
    if (citations.size === 0) {
        return content;
    }

    return content.replace(BARE_CITATION_PATTERN, (match, raw: string) => {
        const numbers = raw
            .split(',')
            .map((part) => Number.parseInt(part.trim(), 10));

        /*
         * Only rewrite when every number in the group resolves — a
         * partial rewrite (e.g. [2] linked, [9] plain text) would leave
         * a broken-looking half-citation. Falling back to the original
         * match keeps the reply readable.
         */
        const resolved = numbers.map((n) => citations.get(n));

        if (resolved.some((c) => c === undefined)) {
            return match;
        }

        return numbers
            .map((n, i) => {
                const citation = resolved[i]!;
                const safeTitle = citation.title.replace(/"/g, '\\"');

                return `[\\[${n}\\]](${citation.url} "${safeTitle}")`;
            })
            .join('');
    });
}
