import { describe, expect, it } from 'vitest';
import type { Message } from '@/types/chat';
import { applyChatStreamEvent } from './chat-state';

function userMessage(id: string | number, content: string): Message {
    return { id, role: 'user', content };
}

function assistantMessage(
    id: string | number,
    content = '',
    extra: Partial<Message> = {},
): Message {
    return { id, role: 'assistant', content, ...extra };
}

describe('applyChatStreamEvent', () => {
    describe('status', () => {
        it('sets status on an empty assistant message', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, '')];
            const after = applyChatStreamEvent(
                before,
                { type: 'status', message: 'Thinking' },
                2,
            );

            expect(after[1].status).toBe('Thinking');
            expect(after[1].content).toBe('');
        });

        it('does not set status once the assistant has started responding', () => {
            const before = [assistantMessage(2, 'already typing')];
            const after = applyChatStreamEvent(
                before,
                { type: 'status', message: 'Thinking' },
                2,
            );

            expect(after[0].status).toBeUndefined();
        });

        it('does not touch other messages', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, '')];
            const after = applyChatStreamEvent(
                before,
                { type: 'status', message: 'Thinking' },
                2,
            );

            expect(after[0]).toEqual(before[0]);
        });
    });

    describe('text_delta', () => {
        it('appends delta content to the assistant message', () => {
            const before = [assistantMessage(2, 'He')];
            const after = applyChatStreamEvent(
                before,
                { type: 'text_delta', delta: 'llo' },
                2,
            );

            expect(after[0].content).toBe('Hello');
        });

        it('accumulates multiple deltas', () => {
            let state = [assistantMessage(2, '')];
            state = applyChatStreamEvent(
                state,
                { type: 'text_delta', delta: 'A' },
                2,
            );
            state = applyChatStreamEvent(
                state,
                { type: 'text_delta', delta: 'B' },
                2,
            );
            state = applyChatStreamEvent(
                state,
                { type: 'text_delta', delta: 'C' },
                2,
            );

            expect(state[0].content).toBe('ABC');
        });

        it('only updates the targeted assistant message', () => {
            const before = [
                assistantMessage(1, 'one'),
                assistantMessage(2, 'two'),
            ];
            const after = applyChatStreamEvent(
                before,
                { type: 'text_delta', delta: '!' },
                2,
            );

            expect(after[0].content).toBe('one');
            expect(after[1].content).toBe('two!');
        });
    });

    describe('tool_call', () => {
        it('appends a tool call to the assistant message', () => {
            const before = [assistantMessage(2)];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'tool_call',
                    tool_id: 't1',
                    tool_name: 'FileReader',
                    arguments: { path: '/tmp/x' },
                },
                2,
            );

            expect(after[0].toolCalls).toEqual([
                {
                    tool_id: 't1',
                    tool_name: 'FileReader',
                    arguments: { path: '/tmp/x' },
                },
            ]);
        });

        it('preserves existing tool calls', () => {
            const before = [
                assistantMessage(2, '', {
                    toolCalls: [
                        {
                            tool_id: 't0',
                            tool_name: 'Existing',
                            arguments: {},
                        },
                    ],
                }),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'tool_call',
                    tool_id: 't1',
                    tool_name: 'New',
                    arguments: {},
                },
                2,
            );

            expect(after[0].toolCalls).toHaveLength(2);
            expect(after[0].toolCalls?.[0].tool_id).toBe('t0');
            expect(after[0].toolCalls?.[1].tool_id).toBe('t1');
        });
    });

    describe('tool_result', () => {
        it('attaches result to the matching tool call', () => {
            const before = [
                assistantMessage(2, '', {
                    toolCalls: [
                        {
                            tool_id: 't1',
                            tool_name: 'FileReader',
                            arguments: {},
                        },
                    ],
                }),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'tool_result',
                    tool_id: 't1',
                    result: 'file contents',
                    successful: true,
                },
                2,
            );

            expect(after[0].toolCalls?.[0]).toMatchObject({
                tool_id: 't1',
                result: 'file contents',
                successful: true,
            });
        });

        it('does not touch unrelated tool calls', () => {
            const before = [
                assistantMessage(2, '', {
                    toolCalls: [
                        { tool_id: 't1', tool_name: 'A', arguments: {} },
                        { tool_id: 't2', tool_name: 'B', arguments: {} },
                    ],
                }),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'tool_result',
                    tool_id: 't1',
                    result: 'ok',
                    successful: true,
                },
                2,
            );

            expect(after[0].toolCalls?.[0].result).toBe('ok');
            expect(after[0].toolCalls?.[1].result).toBeUndefined();
        });

        it('coerces non-string results to strings', () => {
            const before = [
                assistantMessage(2, '', {
                    toolCalls: [
                        { tool_id: 't1', tool_name: 'A', arguments: {} },
                    ],
                }),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'tool_result',
                    tool_id: 't1',
                    result: 42,
                    successful: true,
                },
                2,
            );

            expect(after[0].toolCalls?.[0].result).toBe('42');
        });
    });

    describe('error', () => {
        it('replaces the assistant content with an error prefix', () => {
            const before = [assistantMessage(2, 'streaming...')];
            const after = applyChatStreamEvent(
                before,
                { type: 'error', message: 'model unavailable' },
                2,
            );

            expect(after[0].content).toBe('Error: model unavailable');
        });
    });

    describe('message_usage', () => {
        it('attaches normalized usage and cost to the assistant message', () => {
            const before = [assistantMessage(2, 'reply')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-1',
                    user_message_id: null,
                    usage: { prompt_tokens: 120, completion_tokens: 40 },
                    cost: 0.0034,
                },
                2,
            );

            expect(after[0].usage).toEqual({
                prompt_tokens: 120,
                completion_tokens: 40,
                cache_write_input_tokens: undefined,
                cache_read_input_tokens: undefined,
                reasoning_tokens: undefined,
            });
            expect(after[0].cost).toBe(0.0034);
        });

        it('passes through a null usage payload without crashing', () => {
            const before = [assistantMessage(2, 'reply')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-1',
                    user_message_id: null,
                    usage: null,
                    cost: null,
                },
                2,
            );

            expect(after[0].usage).toBeNull();
            expect(after[0].cost).toBeNull();
        });

        it('swaps the optimistic assistant id for the persisted id', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, 'reply')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-assistant',
                    user_message_id: 'srv-user',
                    usage: null,
                    cost: null,
                },
                2,
            );

            expect(after[1].id).toBe('srv-assistant');
        });

        it('swaps the preceding user message id when user_message_id is set', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, 'reply')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-assistant',
                    user_message_id: 'srv-user',
                    usage: null,
                    cost: null,
                },
                2,
            );

            expect(after[0].id).toBe('srv-user');
        });

        it('leaves the user id alone when user_message_id is null (regenerate)', () => {
            const before = [
                userMessage('existing-user-id', 'hi'),
                assistantMessage(2, 'reply'),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-assistant',
                    user_message_id: null,
                    usage: null,
                    cost: null,
                },
                2,
            );

            expect(after[0].id).toBe('existing-user-id');
            expect(after[1].id).toBe('srv-assistant');
        });

        it('ignores the event when the assistant slot cannot be found', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, 'reply')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'message_usage',
                    message_id: 'srv-assistant',
                    user_message_id: 'srv-user',
                    usage: null,
                    cost: null,
                },
                999,
            );

            expect(after).toBe(before);
        });
    });

    describe('side-effect-only events', () => {
        it('warning does not modify the messages array', () => {
            const before = [assistantMessage(2, 'hi')];
            const after = applyChatStreamEvent(
                before,
                { type: 'warning', message: 'pdftotext missing' },
                2,
            );

            expect(after).toBe(before);
        });

        it('conversation does not modify the messages array', () => {
            const before = [assistantMessage(2, 'hi')];
            const after = applyChatStreamEvent(
                before,
                { type: 'conversation', conversation_id: 'abc' },
                2,
            );

            expect(after).toBe(before);
        });

        it('done does not modify the messages array', () => {
            const before = [assistantMessage(2, 'hi')];
            const after = applyChatStreamEvent(before, { type: 'done' }, 2);

            expect(after).toBe(before);
        });
    });

    describe('unknown event types', () => {
        /*
         * The SSE parser does not validate the `type` field — it casts
         * any JSON frame with a `type` key to StreamEvent. laravel/ai
         * emits provider-specific events (e.g. tool_use_start, usage,
         * message_stop) that don't appear in our StreamEvent union.
         *
         * Any such event that reaches the reducer MUST return the
         * unchanged messages array. Returning undefined (falling out
         * of the switch with no default) wipes messages state to
         * undefined on the next setMessages call and shows the user
         * a "brand new chat" screen mid-stream.
         */
        it('returns unchanged messages for an unknown event type', () => {
            const before = [assistantMessage(2, 'hello')];
            const after = applyChatStreamEvent(
                before,
                { type: 'tool_use_start' } as unknown as Parameters<
                    typeof applyChatStreamEvent
                >[1],
                2,
            );

            expect(after).toBe(before);
            expect(after).not.toBeUndefined();
        });

        it('returns unchanged messages for an event with a missing type', () => {
            const before = [assistantMessage(2, 'hello')];
            const after = applyChatStreamEvent(
                before,
                {} as unknown as Parameters<typeof applyChatStreamEvent>[1],
                2,
            );

            expect(after).toBe(before);
            expect(after).not.toBeUndefined();
        });

        it('returns unchanged messages for a provider-specific event like usage', () => {
            const before = [assistantMessage(2, 'content')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'usage',
                    prompt_tokens: 10,
                    completion_tokens: 20,
                } as unknown as Parameters<typeof applyChatStreamEvent>[1],
                2,
            );

            expect(after).toBe(before);
        });
    });

    describe('phase', () => {
        it('appends the first phase event for a key', () => {
            const before = [assistantMessage(2, '')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'phase',
                    key: 'researcher',
                    label: 'Researching',
                    status: 'running',
                },
                2,
            );

            expect(after[0].phases).toEqual([
                {
                    key: 'researcher',
                    label: 'Researching',
                    status: 'running',
                },
            ]);
        });

        it('upserts the same phase key in place rather than appending', () => {
            const before = [
                assistantMessage(2, '', {
                    phases: [
                        {
                            key: 'researcher',
                            label: 'Researching',
                            status: 'running',
                        },
                    ],
                }),
            ];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'phase',
                    key: 'researcher',
                    label: 'Researching',
                    status: 'complete',
                },
                2,
            );

            expect(after[0].phases).toHaveLength(1);
            expect(after[0].phases?.[0].status).toBe('complete');
        });

        it('preserves existing phases and appends new keys in arrival order', () => {
            let state = [assistantMessage(2, '')];

            for (const [key, label, status] of [
                ['researcher', 'Researching', 'complete'],
                ['editor', 'Editing', 'running'],
                ['critic', 'Reviewing', 'running'],
            ] as const) {
                state = applyChatStreamEvent(
                    state,
                    { type: 'phase', key, label, status },
                    2,
                );
            }

            expect(state[0].phases?.map((p) => p.key)).toEqual([
                'researcher',
                'editor',
                'critic',
            ]);
        });

        it('carries approved/confidence onto the critic phase entry', () => {
            const before = [assistantMessage(2, '')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'phase',
                    key: 'critic',
                    label: 'Reviewing',
                    status: 'complete',
                    approved: false,
                    confidence: 'medium',
                    issues: ['missing sources'],
                    missing_topics: ['pricing'],
                },
                2,
            );

            expect(after[0].phases?.[0]).toMatchObject({
                approved: false,
                confidence: 'medium',
                issues: ['missing sources'],
                missing_topics: ['pricing'],
            });
        });

        it('does not touch other messages', () => {
            const before = [userMessage(1, 'hi'), assistantMessage(2, '')];
            const after = applyChatStreamEvent(
                before,
                {
                    type: 'phase',
                    key: 'researcher',
                    label: 'Researching',
                    status: 'running',
                },
                2,
            );

            expect(after[0]).toEqual(before[0]);
        });
    });

    describe('immutability', () => {
        it('returns a new array when modifying messages', () => {
            const before = [assistantMessage(2, 'He')];
            const after = applyChatStreamEvent(
                before,
                { type: 'text_delta', delta: 'llo' },
                2,
            );

            expect(after).not.toBe(before);
            expect(before[0].content).toBe('He');
        });
    });
});
