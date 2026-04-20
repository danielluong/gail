import type { Message } from '@/types/chat';
import type { StreamEvent } from './sse';

/**
 * Pure reducer for the chat message list under an SSE stream. Given the
 * current messages array and a single StreamEvent targeting the given
 * assistantMessageId, returns the next messages array.
 *
 * The reducer is intentionally the only place message-array state
 * transitions are defined, so the streaming protocol can be tested
 * exhaustively without mounting React. Side-effecting events
 * (`warning` surfaces a toast; `conversation` updates the active
 * conversation id) don't mutate the messages array and are handled
 * outside this function — `useChat` dispatches them separately.
 *
 * **Runtime safety:** TypeScript's `StreamEvent` union is not enforced
 * at runtime — `parseSseStream` casts any JSON frame with a `type` key
 * to `StreamEvent`, so laravel/ai and provider-specific events we
 * haven't modelled can reach this function. Any unknown event type
 * MUST return the unchanged messages array. Returning `undefined` (by
 * falling out of the switch) would wipe messages state on the next
 * `setMessages` call and show the user a blank "new chat" mid-stream.
 * That bug shipped in d47bf36 and the default case below is the fix.
 */
export function applyChatStreamEvent(
    messages: Message[],
    event: StreamEvent,
    assistantMessageId: string | number,
): Message[] {
    switch (event.type) {
        case 'status':
            return messages.map((msg) =>
                msg.id === assistantMessageId && msg.content === ''
                    ? { ...msg, status: event.message }
                    : msg,
            );

        case 'text_delta':
            return messages.map((msg) =>
                msg.id === assistantMessageId
                    ? { ...msg, content: msg.content + event.delta }
                    : msg,
            );

        case 'tool_call':
            return messages.map((msg) =>
                msg.id === assistantMessageId
                    ? {
                          ...msg,
                          toolCalls: [
                              ...(msg.toolCalls ?? []),
                              {
                                  tool_id: event.tool_id,
                                  tool_name: event.tool_name,
                                  arguments: event.arguments ?? {},
                              },
                          ],
                      }
                    : msg,
            );

        case 'tool_result':
            return messages.map((msg) =>
                msg.id === assistantMessageId
                    ? {
                          ...msg,
                          toolCalls: (msg.toolCalls ?? []).map((tc) =>
                              tc.tool_id === event.tool_id
                                  ? {
                                        ...tc,
                                        result: String(event.result ?? ''),
                                        successful: event.successful,
                                        error: event.error,
                                    }
                                  : tc,
                          ),
                      }
                    : msg,
            );

        case 'error':
            return messages.map((msg) =>
                msg.id === assistantMessageId
                    ? { ...msg, content: `Error: ${event.message}` }
                    : msg,
            );

        case 'message_usage': {
            /*
             * message_usage is the last chance in the stream to swap the
             * optimistic numeric ids (assigned client-side before the
             * server persisted anything) for the real database ids. Until
             * the swap, `typeof id === 'string'` is false and the Edit /
             * Branch buttons stay hidden — see message-actions.tsx.
             *
             * `user_message_id` is null on regenerate (no new user row
             * was kept), so we skip the user-side swap in that case.
             */
            const assistantIndex = messages.findIndex(
                (m) => m.id === assistantMessageId,
            );

            if (assistantIndex === -1) {
                return messages;
            }

            const usage = event.usage
                ? {
                      prompt_tokens: event.usage.prompt_tokens ?? 0,
                      completion_tokens: event.usage.completion_tokens ?? 0,
                      cache_write_input_tokens:
                          event.usage.cache_write_input_tokens,
                      cache_read_input_tokens:
                          event.usage.cache_read_input_tokens,
                      reasoning_tokens: event.usage.reasoning_tokens,
                  }
                : null;

            const userIndex =
                event.user_message_id !== null &&
                assistantIndex > 0 &&
                messages[assistantIndex - 1].role === 'user'
                    ? assistantIndex - 1
                    : -1;

            return messages.map((msg, idx) => {
                if (idx === assistantIndex) {
                    return {
                        ...msg,
                        id: event.message_id,
                        usage,
                        cost: event.cost,
                    };
                }

                if (idx === userIndex) {
                    return { ...msg, id: event.user_message_id as string };
                }

                return msg;
            });
        }

        case 'warning':
        case 'conversation':
        case 'done':
            return messages;

        default:
            // Unknown / provider-specific event type. Preserve state.
            return messages;
    }
}
