import { describe, expect, it } from 'vitest';
import { parseSseStream } from './sse';
import type { StreamEvent } from './sse';

function streamFromChunks(chunks: string[]): ReadableStream<Uint8Array> {
    const encoder = new TextEncoder();

    return new ReadableStream<Uint8Array>({
        start(controller) {
            for (const chunk of chunks) {
                controller.enqueue(encoder.encode(chunk));
            }

            controller.close();
        },
    });
}

async function collect(
    stream: ReadableStream<Uint8Array>,
): Promise<StreamEvent[]> {
    const out: StreamEvent[] = [];

    for await (const event of parseSseStream(stream)) {
        out.push(event);
    }

    return out;
}

describe('parseSseStream', () => {
    it('parses a single text_delta frame', async () => {
        const events = await collect(
            streamFromChunks(['data: {"type":"text_delta","delta":"Hi"}\n\n']),
        );

        expect(events).toEqual([{ type: 'text_delta', delta: 'Hi' }]);
    });

    it('emits a done event for [DONE]', async () => {
        const events = await collect(streamFromChunks(['data: [DONE]\n\n']));

        expect(events).toEqual([{ type: 'done' }]);
    });

    it('parses multiple frames in one chunk', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":"text_delta","delta":"Hello"}\n\n' +
                    'data: {"type":"text_delta","delta":" world"}\n\n' +
                    'data: [DONE]\n\n',
            ]),
        );

        expect(events).toEqual([
            { type: 'text_delta', delta: 'Hello' },
            { type: 'text_delta', delta: ' world' },
            { type: 'done' },
        ]);
    });

    it('reassembles a frame split across chunks', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":"text_de',
                'lta","delta":"split"}\n\n',
            ]),
        );

        expect(events).toEqual([{ type: 'text_delta', delta: 'split' }]);
    });

    it('handles a frame split across three chunks', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":',
                '"text_delta",',
                '"delta":"three"}\n\n',
            ]),
        );

        expect(events).toEqual([{ type: 'text_delta', delta: 'three' }]);
    });

    it('ignores lines that do not start with "data: "', async () => {
        const events = await collect(
            streamFromChunks([
                ': heartbeat\n',
                'event: ping\n',
                'data: {"type":"text_delta","delta":"x"}\n\n',
            ]),
        );

        expect(events).toEqual([{ type: 'text_delta', delta: 'x' }]);
    });

    it('tolerates malformed JSON by skipping the frame', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {not-json\n\n',
                'data: {"type":"text_delta","delta":"ok"}\n\n',
            ]),
        );

        expect(events).toEqual([{ type: 'text_delta', delta: 'ok' }]);
    });

    it('parses a tool_call frame with arguments', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":"tool_call","tool_id":"t1","tool_name":"FileReader","arguments":{"path":"/tmp/x"}}\n\n',
            ]),
        );

        expect(events).toEqual([
            {
                type: 'tool_call',
                tool_id: 't1',
                tool_name: 'FileReader',
                arguments: { path: '/tmp/x' },
            },
        ]);
    });

    it('parses a tool_result frame', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":"tool_result","tool_id":"t1","result":"ok","successful":true}\n\n',
            ]),
        );

        expect(events).toEqual([
            {
                type: 'tool_result',
                tool_id: 't1',
                result: 'ok',
                successful: true,
            },
        ]);
    });

    it('parses the final conversation frame', async () => {
        const events = await collect(
            streamFromChunks([
                'data: {"type":"conversation","conversation_id":"abc-123"}\n\n',
            ]),
        );

        expect(events).toEqual([
            { type: 'conversation', conversation_id: 'abc-123' },
        ]);
    });

    it('yields a trailing frame even without a final newline', async () => {
        const events = await collect(
            streamFromChunks(['data: {"type":"done"}']),
        );

        expect(events).toEqual([{ type: 'done' }]);
    });
});
