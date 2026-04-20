import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useConversationOps } from '@/hooks/use-conversation-ops';
import { useMessageStream } from '@/hooks/use-message-stream';
import { getStored, removeStored, setStored } from '@/lib/storage';
import type { Conversation } from '@/types/chat';

const LAST_CONVERSATION_STORAGE_KEY = 'gail-last-conversation';

/**
 * Composition hook consumed by the chat page. Delegates the SSE + message
 * engine to useMessageStream and navigation (load / branch / delete) to
 * useConversationOps — this file is only responsible for stitching them
 * together, restoring the last-opened conversation on mount, mirroring
 * the conversations list so stream completions can patch it in place
 * without a full Inertia reload, and wrapping regenerate/edit/branch in
 * stable refs so memoized message rows don't rerender on every token.
 */
export function useChat(initialConversations: Conversation[]) {
    /*
     * Mirror the incoming `conversations` prop so we can update a
     * single row (e.g. bump updated_at after a stream) without asking
     * Inertia to refetch the full list. The effect below re-syncs
     * whenever the server explicitly reloads the prop — new items,
     * titles, renames, deletes all flow through via router.reload
     * calls elsewhere.
     */
    const [conversations, setConversations] = useState(initialConversations);

    useEffect(() => {
        setConversations(initialConversations);
    }, [initialConversations]);

    function reloadConversations() {
        router.reload({ only: ['conversations'] });
    }

    function bumpConversationTimestamp(id: string) {
        const now = new Date().toISOString();

        setConversations((prev) => {
            const target = prev.find((c) => c.id === id);

            if (target === undefined) {
                return prev;
            }

            /*
             * Move the active conversation to the head of its group
             * so the sidebar's date-bucket grouping matches the
             * server-side `order by is_pinned desc, updated_at desc`
             * without another fetch.
             */
            const rest = prev.filter((c) => c.id !== id);

            return [{ ...target, updated_at: now }, ...rest];
        });
    }

    const stream = useMessageStream(({ wasNew, conversationId }) => {
        if (wasNew) {
            reloadConversations();

            return;
        }

        bumpConversationTimestamp(conversationId);
    });
    const ops = useConversationOps(conversations, stream.handle);

    useEffect(() => {
        if (stream.conversationId) {
            setStored(LAST_CONVERSATION_STORAGE_KEY, stream.conversationId);
        }
    }, [stream.conversationId]);

    const restoredRef = useRef(false);

    useEffect(() => {
        if (restoredRef.current) {
            return;
        }

        restoredRef.current = true;

        const stored = getStored<string | null>(
            LAST_CONVERSATION_STORAGE_KEY,
            null,
        );

        if (!stored) {
            return;
        }

        if (conversations.some((c) => c.id === stored)) {
            ops.loadConversation(stored);
        } else {
            removeStored(LAST_CONVERSATION_STORAGE_KEY);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function handleConversationDeleted(deletedId: string) {
        if (stream.conversationId === deletedId) {
            stream.startNewChat();
        }

        const stored = getStored<string | null>(
            LAST_CONVERSATION_STORAGE_KEY,
            null,
        );

        if (stored === deletedId) {
            removeStored(LAST_CONVERSATION_STORAGE_KEY);
        }

        reloadConversations();
    }

    function startNewChat(projectId: number | null = null) {
        stream.startNewChat(projectId);
        removeStored(LAST_CONVERSATION_STORAGE_KEY);
    }

    /*
     * Row-level handlers wrapped in stable-identity callbacks so memoized
     * message components skip re-rendering on every token. The ref is
     * refreshed on every render; the wrapper's identity stays the same.
     */
    const handlersRef = useRef({
        handleRegenerate: stream.handleRegenerate,
        handleEditMessage: stream.handleEditMessage,
        handleBranchFromMessage: ops.handleBranchFromMessage,
    });

    useEffect(() => {
        handlersRef.current = {
            handleRegenerate: stream.handleRegenerate,
            handleEditMessage: stream.handleEditMessage,
            handleBranchFromMessage: ops.handleBranchFromMessage,
        };
    });

    const stableHandleRegenerate = useCallback(
        () => handlersRef.current.handleRegenerate(),
        [],
    );
    const stableHandleEditMessage = useCallback(
        (id: string | number, content: string) =>
            handlersRef.current.handleEditMessage(id, content),
        [],
    );
    const stableHandleBranchFromMessage = useCallback(
        (id: string | number) =>
            handlersRef.current.handleBranchFromMessage(id),
        [],
    );

    return {
        conversations,
        messages: stream.messages,
        input: stream.input,
        setInput: stream.setInput,
        isStreaming: stream.isStreaming,
        conversationId: stream.conversationId,
        activeProjectId: stream.activeProjectId,
        loadingConversation: ops.loadingConversation,
        model: stream.model,
        setModel: stream.setModel,
        temperature: stream.temperature,
        setTemperature: stream.setTemperature,
        agent: stream.agent,
        setAgent: stream.setAgent,
        startNewChat,
        loadConversation: ops.loadConversation,
        handleConversationDeleted,
        handleSubmit: stream.handleSubmit,
        handleStop: stream.handleStop,
        handleRegenerate: stableHandleRegenerate,
        handleEditMessage: stableHandleEditMessage,
        handleBranchFromMessage: stableHandleBranchFromMessage,
    };
}
