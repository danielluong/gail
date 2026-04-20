import { describe, expect, it } from 'vitest';
import type { ToolCall } from '@/types/chat';
import { extractCitations, linkifyCitations } from './citations';

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
