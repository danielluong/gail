import type { MutableRefObject } from 'react';
import { useRef, useState } from 'react';
import ChatController from '@/actions/App/Http/Controllers/ChatController';
import { showToast } from '@/components/toast';
import { useChatSettings } from '@/hooks/use-chat-settings';
import { apiFetch } from '@/lib/api';
import { applyChatStreamEvent } from '@/lib/chat-state';
import { parseSseStream } from '@/lib/sse';
import type { StreamEvent } from '@/lib/sse';
import type { Attachment, Message, MessageVariant } from '@/types/chat';

class StreamError extends Error {
    constructor(public status: number) {
        super(`Stream failed with status ${status}`);
    }
}

function describeStreamError(error: unknown): string {
    if (error instanceof StreamError) {
        if (error.status === 0) {
            return 'Network error — check your connection and try again.';
        }

        if (error.status === 422) {
            return 'The request was invalid. Please try a different message.';
        }

        if (error.status === 429) {
            return 'Too many requests. Please wait a moment and try again.';
        }

        if (error.status >= 500) {
            return 'The server ran into an error. Please try again in a moment.';
        }

        return `Request failed (HTTP ${error.status}).`;
    }

    if (error instanceof TypeError) {
        return 'Network error — check your connection and try again.';
    }

    return 'Sorry, something went wrong. Please try again.';
}

export interface MessageStreamHandle {
    messages: Message[];
    setMessages: React.Dispatch<React.SetStateAction<Message[]>>;
    conversationId: string | null;
    setConversationId: React.Dispatch<React.SetStateAction<string | null>>;
    setActiveProjectId: React.Dispatch<React.SetStateAction<number | null>>;
    nextId: MutableRefObject<number>;
    isStreaming: boolean;
    startNewChat: (projectId?: number | null) => void;
}

export interface StreamCompletion {
    /**
     * True when the turn created a new conversation (no prior id at
     * stream start). The caller typically does a full reload in this
     * case so the backend-generated title can replace the placeholder.
     */
    wasNew: boolean;
    /** The conversation id as of the final SSE frame. */
    conversationId: string;
}

/**
 * Owns the SSE + optimistic-message engine for a chat turn. Persists
 * settings via useChatSettings and exposes a MessageStreamHandle so
 * sibling hooks (see useConversationOps) can read/write the same
 * message state without duplicating it.
 *
 * onStreamComplete fires after a successful stream so the caller can
 * decide how to refresh the sidebar conversation list — e.g. in-place
 * updated_at bump for continuing turns, full reload for new turns.
 */
export function useMessageStream(
    onStreamComplete?: (completion: StreamCompletion) => void,
) {
    const [messages, setMessages] = useState<Message[]>([]);
    const [input, setInput] = useState('');
    const [isStreaming, setIsStreaming] = useState(false);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [activeProjectId, setActiveProjectId] = useState<number | null>(null);
    const { model, setModel, temperature, setTemperature, agent, setAgent } =
        useChatSettings();
    const nextId = useRef(0);
    const abortControllerRef = useRef<AbortController | null>(null);
    /*
     * Hold the final conversation id from the SSE `conversation` event
     * in a ref so streamResponse can read it synchronously after the
     * stream ends — setConversationId state closures still see the
     * pre-event value for the life of the current run.
     */
    const currentConversationIdRef = useRef<string | null>(null);

    function startNewChat(projectId: number | null = null) {
        setMessages([]);
        setConversationId(null);
        setActiveProjectId(projectId);
        setInput('');
    }

    function applyStreamEvent(
        event: StreamEvent,
        assistantMessageId: string | number,
    ) {
        if (event.type === 'warning') {
            showToast(event.message, 'error');

            return;
        }

        if (event.type === 'conversation') {
            currentConversationIdRef.current = event.conversation_id;
            setConversationId(event.conversation_id);

            return;
        }

        if (event.type === 'done') {
            return;
        }

        /*
         * Whitelist the event types the reducer is guaranteed to
         * handle. Without this, an unknown/provider-specific event
         * (laravel/ai emits types beyond our TypeScript union) would
         * reach the reducer and — before the default case was added —
         * return undefined, wiping messages state to undefined on the
         * next render. The reducer now has a default, but this
         * whitelist is a second line of defence: unknown types are
         * dropped without ever touching state.
         */
        const MESSAGE_EVENT_TYPES = new Set<StreamEvent['type']>([
            'status',
            'text_delta',
            'tool_call',
            'tool_result',
            'message_usage',
            'phase',
            'error',
        ]);

        if (!MESSAGE_EVENT_TYPES.has(event.type)) {
            return;
        }

        setMessages((prev) =>
            applyChatStreamEvent(prev, event, assistantMessageId),
        );
    }

    async function streamResponse(
        userContent: string,
        assistantMessageId: string | number,
        filePaths: string[] = [],
        editMessageId?: string,
        regenerate = false,
    ) {
        const controller = new AbortController();
        abortControllerRef.current = controller;
        const startingConversationId = conversationId;

        currentConversationIdRef.current = startingConversationId;
        setIsStreaming(true);

        try {
            const response = await apiFetch(ChatController.stream.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify({
                    message: userContent,
                    conversation_id: startingConversationId,
                    project_id: startingConversationId
                        ? undefined
                        : activeProjectId,
                    model,
                    agent,
                    temperature,
                    edit_message_id: editMessageId,
                    regenerate: regenerate || undefined,
                    file_paths: filePaths.length > 0 ? filePaths : undefined,
                }),
                signal: controller.signal,
            });

            if (!response.ok || !response.body) {
                throw new StreamError(response.status);
            }

            for await (const event of parseSseStream(response.body)) {
                applyStreamEvent(event, assistantMessageId);
            }

            const finalId = currentConversationIdRef.current;

            if (finalId !== null) {
                onStreamComplete?.({
                    wasNew: startingConversationId === null,
                    conversationId: finalId,
                });
            }
        } catch (error) {
            if (controller.signal.aborted) {
                const finalId = currentConversationIdRef.current;

                if (finalId !== null) {
                    onStreamComplete?.({
                        wasNew: startingConversationId === null,
                        conversationId: finalId,
                    });
                }

                return;
            }

            setMessages((prev) =>
                prev.map((msg) =>
                    msg.id === assistantMessageId
                        ? {
                              ...msg,
                              content: describeStreamError(error),
                              error: true,
                          }
                        : msg,
                ),
            );
        } finally {
            if (abortControllerRef.current === controller) {
                abortControllerRef.current = null;
            }

            setIsStreaming(false);
        }
    }

    function handleStop() {
        abortControllerRef.current?.abort();
    }

    async function handleSubmit(attachments: Attachment[] = []) {
        const trimmed = input.trim();

        if ((!trimmed && attachments.length === 0) || isStreaming) {
            return;
        }

        const now = new Date().toISOString();

        const userMessage: Message = {
            id: nextId.current++,
            role: 'user',
            content: trimmed,
            attachments: attachments.length > 0 ? attachments : undefined,
            created_at: now,
        };

        const assistantMessage: Message = {
            id: nextId.current++,
            role: 'assistant',
            content: '',
            model: model ?? undefined,
            created_at: now,
        };

        setMessages((prev) => [...prev, userMessage, assistantMessage]);
        setInput('');

        const filePaths = attachments
            .map((a) => a.path)
            .filter((p): p is string => typeof p === 'string' && p.length > 0);

        await streamResponse(trimmed, assistantMessage.id, filePaths);
    }

    async function handleRegenerate() {
        if (isStreaming || messages.length < 2) {
            return;
        }

        const lastAssistant = messages[messages.length - 1];
        const lastUser = messages[messages.length - 2];

        if (lastAssistant.role !== 'assistant' || lastUser.role !== 'user') {
            return;
        }

        const now = new Date().toISOString();

        setMessages((prev) =>
            prev.map((msg) => {
                if (msg.id !== lastAssistant.id) {
                    return msg;
                }

                /*
                 * Preserve the outgoing response as the tail of the
                 * variants list so the user can flip back to it while
                 * the new stream runs. The server returns variants in
                 * chronological order excluding the current/latest, so
                 * appending here matches the same convention.
                 */
                const snapshot: MessageVariant = {
                    id:
                        typeof msg.id === 'string'
                            ? msg.id
                            : `pending-${msg.id}`,
                    role: 'assistant',
                    content: msg.content,
                    toolCalls: msg.toolCalls,
                    attachments: msg.attachments,
                    model: msg.model,
                    usage: msg.usage,
                    cost: msg.cost,
                    phases: msg.phases,
                    created_at: msg.created_at,
                };

                const priorVariants = msg.variants ?? [];

                /*
                 * Reset the per-turn state the new stream will repaint.
                 * `phases` and `error` / `status` all have to be
                 * explicitly zeroed out — not just the visible content
                 * — otherwise a prior errored or research turn leaves
                 * residue that overrides the render path for the empty
                 * streaming bubble (ThinkingIndicator hides whenever
                 * the bubble still has non-empty content, even if
                 * that content is a stale error message from the
                 * previous attempt).
                 */
                return {
                    ...msg,
                    content: '',
                    toolCalls: [],
                    phases: [],
                    error: false,
                    status: undefined,
                    model: model ?? undefined,
                    usage: null,
                    cost: null,
                    created_at: now,
                    variants: [...priorVariants, snapshot],
                };
            }),
        );

        await streamResponse(
            lastUser.content,
            lastAssistant.id,
            [],
            undefined,
            true,
        );
    }

    async function handleEditMessage(
        messageId: string | number,
        newContent: string,
    ) {
        if (isStreaming) {
            return;
        }

        /*
         * Server-side truncation is keyed by the message's persisted id,
         * so optimistic in-memory messages (numeric ids assigned by the
         * client while streaming) can't be edited until the conversation
         * is reloaded. The edit button is gated accordingly — matches the
         * branch flow's existing guard.
         */
        if (typeof messageId !== 'string') {
            return;
        }

        const trimmed = newContent.trim();

        if (!trimmed) {
            return;
        }

        const editIndex = messages.findIndex((m) => m.id === messageId);

        if (editIndex === -1) {
            return;
        }

        const now = new Date().toISOString();
        const userMessage: Message = {
            id: nextId.current++,
            role: 'user',
            content: trimmed,
            created_at: now,
        };
        const assistantMessage: Message = {
            id: nextId.current++,
            role: 'assistant',
            content: '',
            model: model ?? undefined,
            created_at: now,
        };

        setMessages((prev) => [
            ...prev.slice(0, editIndex),
            userMessage,
            assistantMessage,
        ]);

        await streamResponse(trimmed, assistantMessage.id, [], messageId);
    }

    const handle: MessageStreamHandle = {
        messages,
        setMessages,
        conversationId,
        setConversationId,
        setActiveProjectId,
        nextId,
        isStreaming,
        startNewChat,
    };

    return {
        messages,
        input,
        setInput,
        isStreaming,
        conversationId,
        activeProjectId,
        model,
        setModel,
        temperature,
        setTemperature,
        agent,
        setAgent,
        startNewChat,
        handleSubmit,
        handleStop,
        handleRegenerate,
        handleEditMessage,
        handle,
    };
}
