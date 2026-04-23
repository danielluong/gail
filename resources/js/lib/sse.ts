/**
 * Pure SSE event parser for Gail's chat stream. Takes an async iterable
 * of byte chunks (typically from `response.body`) and yields parsed
 * events. The parser knows nothing about React or network fetch
 * semantics — it is unit-testable in isolation against recorded fixture
 * bytes.
 */

export type StreamEvent =
    | { type: 'status'; message: string }
    | { type: 'warning'; message: string }
    | { type: 'text_delta'; delta: string }
    | {
          type: 'tool_call';
          tool_id: string;
          tool_name: string;
          arguments: Record<string, unknown>;
      }
    | {
          type: 'tool_result';
          tool_id: string;
          result?: unknown;
          successful?: boolean;
          error?: string;
      }
    | {
          type: 'message_usage';
          message_id: string;
          user_message_id: string | null;
          usage: {
              prompt_tokens?: number;
              completion_tokens?: number;
              cache_write_input_tokens?: number;
              cache_read_input_tokens?: number;
              reasoning_tokens?: number;
          } | null;
          cost: number | null;
      }
    | { type: 'conversation'; conversation_id: string }
    | { type: 'error'; message: string }
    | {
          type: 'phase';
          key: string;
          label: string;
          status: 'running' | 'complete' | 'failed';
          approved?: boolean;
          confidence?: 'low' | 'medium' | 'high';
          issues?: string[];
          missing_topics?: string[];
      }
    | { type: 'done' };

export async function* parseSseStream(
    body: ReadableStream<Uint8Array>,
): AsyncGenerator<StreamEvent> {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });

        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';

        for (const line of lines) {
            const event = parseLine(line);

            if (event) {
                yield event;
            }
        }
    }

    const final = parseLine(buffer.trim());

    if (final) {
        yield final;
    }
}

function parseLine(line: string): StreamEvent | null {
    if (!line.startsWith('data: ')) {
        return null;
    }

    const data = line.slice(6);

    if (data === '[DONE]') {
        return { type: 'done' };
    }

    try {
        const parsed = JSON.parse(data) as StreamEvent;

        if (parsed && typeof parsed === 'object' && 'type' in parsed) {
            return parsed;
        }
    } catch {
        // ignore non-JSON lines; the server never emits them but tolerating
        // malformed frames prevents one bad byte killing the whole stream.
    }

    return null;
}
