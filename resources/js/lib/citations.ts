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

/*
 * The research EditorAgent closes its answer with a `## Sources` (or
 * `### Source`) section containing a numbered list. Those items — not
 * the Researcher's tool calls — are the canonical citation map for a
 * research answer, since the Editor curates them into final form.
 *
 * Accepts lines like:
 *   1. https://example.com
 *   2. [Title](https://example.com)
 *   3. Title — https://example.com
 *
 * The section is matched from the first Sources heading through the
 * next heading of any level or end of string — good enough for the
 * Editor's one-section-per-answer contract without needing a full
 * Markdown parse.
 */
const SOURCES_SECTION_PATTERN =
    /(?:^|\n)#{1,6}\s+Sources?\s*\n([\s\S]*?)(?=\n#{1,6}\s|$)/i;
const SOURCES_LINE_PATTERN = /^\s*(\d+)\.\s+(.+)$/gm;
const MARKDOWN_LINK_PATTERN = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/;
const BARE_URL_PATTERN = /https?:\/\/\S+/;

export function extractSourcesFromMarkdown(
    content: string,
): Map<number, Citation> {
    const citations = new Map<number, Citation>();

    if (!content) {
        return citations;
    }

    const section = content.match(SOURCES_SECTION_PATTERN);

    if (!section) {
        return citations;
    }

    for (const line of section[1].matchAll(SOURCES_LINE_PATTERN)) {
        const n = Number.parseInt(line[1], 10);

        if (!Number.isFinite(n)) {
            continue;
        }

        const rest = line[2].trim();
        const mdLink = rest.match(MARKDOWN_LINK_PATTERN);

        if (mdLink) {
            citations.set(n, {
                title: mdLink[1].trim(),
                url: mdLink[2].trim(),
            });

            continue;
        }

        const urlMatch = rest.match(BARE_URL_PATTERN);

        if (!urlMatch) {
            continue;
        }

        /*
         * Strip trailing ),.; that punctuation captures because `\S+`
         * is greedy — URLs rarely end in them and when they do,
         * dropping them is safer than a broken link.
         */
        const url = urlMatch[0].replace(/[),.;]+$/, '');
        const preamble = rest
            .slice(0, rest.indexOf(urlMatch[0]))
            .replace(/[\s—–\-:|]+$/, '')
            .trim();

        citations.set(n, { title: preamble !== '' ? preamble : url, url });
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
