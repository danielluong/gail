import { describe, expect, it } from 'vitest';
import type { ToolCall } from '@/types/chat';
import {
    extractCitations,
    extractSourcesFromMarkdown,
    linkifyCitations,
} from './citations';

function webSearch(result: string): ToolCall {
    return {
        tool_id: 'call_1',
        tool_name: 'WebSearch',
        arguments: {},
        result,
    };
}

describe('extractCitations', () => {
    it('returns an empty map when there are no web search tool calls', () => {
        expect(extractCitations(undefined).size).toBe(0);
        expect(extractCitations([]).size).toBe(0);
    });

    it('ignores tool calls from other tools', () => {
        const calls: ToolCall[] = [
            {
                tool_id: 'call_1',
                tool_name: 'Calculator',
                arguments: { expression: '1 + 1' },
                result: '1. Not a citation\n   https://nope.example',
            },
        ];

        expect(extractCitations(calls).size).toBe(0);
    });

    it('parses numbered WebSearch results into number → URL mapping', () => {
        const call = webSearch(
            [
                'Search results for "pizza":',
                '',
                '1. Di Fara Pizza',
                '   https://difara.example',
                '   classic Brooklyn slice',
                '',
                '2. L&B Spumoni Gardens',
                '   https://spumoni.example',
                '   famous square pie',
            ].join('\n'),
        );

        const citations = extractCitations([call]);

        expect(citations.size).toBe(2);
        expect(citations.get(1)).toEqual({
            url: 'https://difara.example',
            title: 'Di Fara Pizza',
        });
        expect(citations.get(2)).toEqual({
            url: 'https://spumoni.example',
            title: 'L&B Spumoni Gardens',
        });
    });

    it('lets later web searches override earlier same-number entries', () => {
        const first = webSearch('1. First\n   https://first.example');
        const second = webSearch('1. Second\n   https://second.example');

        const citations = extractCitations([first, second]);

        expect(citations.get(1)?.url).toBe('https://second.example');
    });
});

describe('extractSourcesFromMarkdown', () => {
    it('returns an empty map when no Sources section is present', () => {
        const content = '## Summary\n\nJust a summary, no sources.';

        expect(extractSourcesFromMarkdown(content).size).toBe(0);
    });

    it('parses a numbered list of bare URLs', () => {
        const content = [
            '## Summary',
            '',
            'Solar wins on cost [1]. Nuclear wins on density [2].',
            '',
            '## Sources',
            '',
            '1. https://solar.example',
            '2. https://nuclear.example',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.get(1)?.url).toBe('https://solar.example');
        expect(citations.get(2)?.url).toBe('https://nuclear.example');
    });

    it('parses markdown-linked sources, preserving the link title', () => {
        const content = [
            '## Sources',
            '',
            '1. [Solar Basics](https://solar.example)',
            '2. [Nuclear Lifecycle](https://nuclear.example)',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.get(1)).toEqual({
            url: 'https://solar.example',
            title: 'Solar Basics',
        });
        expect(citations.get(2)).toEqual({
            url: 'https://nuclear.example',
            title: 'Nuclear Lifecycle',
        });
    });

    it('uses the text before the URL as the title for "Title — URL" format', () => {
        const content = [
            '## Sources',
            '',
            '1. Solar Basics — https://solar.example',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.get(1)).toEqual({
            url: 'https://solar.example',
            title: 'Solar Basics',
        });
    });

    it('matches `### Source` and `# Sources` variants case-insensitively', () => {
        const content = [
            '### source',
            '',
            '1. https://one.example',
            '',
            '# SOURCES',
            '',
            '2. https://two.example',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        // First Sources heading wins; second-heading URLs would only match
        // if we extended to multi-section support, which we don't need.
        expect(citations.get(1)?.url).toBe('https://one.example');
    });

    it('stops at the next heading and does not eat unrelated trailing content', () => {
        const content = [
            '## Sources',
            '',
            '1. https://a.example',
            '',
            '## Notes',
            '',
            '2. https://b.example — not a source',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.size).toBe(1);
        expect(citations.get(1)?.url).toBe('https://a.example');
    });

    it('strips trailing punctuation that greedy URL matching captures', () => {
        const content = ['## Sources', '', '1. (see https://a.example).'].join(
            '\n',
        );

        expect(extractSourcesFromMarkdown(content).get(1)?.url).toBe(
            'https://a.example',
        );
    });

    it('falls back to the URL as title when no preamble text is present', () => {
        const content = ['## Sources', '', '1. https://a.example'].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.get(1)).toEqual({
            url: 'https://a.example',
            title: 'https://a.example',
        });
    });

    it('ignores numbered items that have no URL at all', () => {
        const content = [
            '## Sources',
            '',
            '1. No URL here',
            '2. https://good.example',
        ].join('\n');

        const citations = extractSourcesFromMarkdown(content);

        expect(citations.has(1)).toBe(false);
        expect(citations.get(2)?.url).toBe('https://good.example');
    });
});

describe('linkifyCitations', () => {
    const citations = new Map([
        [1, { url: 'https://a.example', title: 'Source A' }],
        [2, { url: 'https://b.example', title: 'Source B' }],
    ]);

    it('returns the original content when there are no citations', () => {
        expect(linkifyCitations('hello [1] world', new Map())).toBe(
            'hello [1] world',
        );
    });

    it('rewrites bare [N] markers into markdown links', () => {
        const result = linkifyCitations('Per [1], pizza is great.', citations);

        expect(result).toBe(
            'Per [\\[1\\]](https://a.example "Source A"), pizza is great.',
        );
    });

    it('leaves already-linked brackets alone', () => {
        const content = 'Already [1](https://other.example) linked.';

        expect(linkifyCitations(content, citations)).toBe(content);
    });

    it('leaves markdown images alone', () => {
        const content = 'Image: ![1](https://img.example/pie.jpg)';

        expect(linkifyCitations(content, citations)).toBe(content);
    });

    it('leaves unknown citation numbers untouched', () => {
        expect(linkifyCitations('See [9].', citations)).toBe('See [9].');
    });

    it('rewrites comma-separated citation groups as adjacent links', () => {
        const result = linkifyCitations('See [1, 2].', citations);

        expect(result).toBe(
            'See [\\[1\\]](https://a.example "Source A")[\\[2\\]](https://b.example "Source B").',
        );
    });

    it('leaves a comma-separated group alone if any number is unknown', () => {
        expect(linkifyCitations('See [1, 9].', citations)).toBe('See [1, 9].');
    });

    it('escapes quote characters in the title tooltip', () => {
        const weird = new Map([
            [1, { url: 'https://a.example', title: 'She said "hi"' }],
        ]);

        expect(linkifyCitations('[1]', weird)).toBe(
            '[\\[1\\]](https://a.example "She said \\"hi\\"")',
        );
    });
});
