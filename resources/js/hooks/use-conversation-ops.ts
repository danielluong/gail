import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { showToast } from '@/components/toast';
import type { MessageStreamHandle } from '@/hooks/use-message-stream';
import { apiFetch, apiJson } from '@/lib/api';
import type { Conversation, Message } from '@/types/chat';

/**
 * Load, branch, and delete operations on conversations. Owns only
 * `loadingConversation` state; all message-side mutations flow through
 * the MessageStreamHandle so the two hooks stay consistent without
 * passing data around as props.
 */
export function useConversationOps(
    conversations: Conversation[],
    handle: MessageStreamHandle,
) {
    const [loadingConversation, setLoadingConversation] = useState(false);

    async function loadConversation(id: string) {
        if (id === handle.conversationId || loadingConversation) {
            return;
        }

        setLoadingConversation(true);

        try {
            const response = await apiFetch(
                ConversationController.messages.url(id),
                {
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to load conversation');
            }

            const data: Message[] = await response.json();
            handle.setMessages(data);
            handle.setConversationId(id);
            const convo = conversations.find((c) => c.id === id);
            handle.setActiveProjectId(convo?.project_id ?? null);
            handle.nextId.current = data.length;
        } catch {
            showToast(
                'Failed to load conversation. Please try again.',
                'error',
            );
        } finally {
            setLoadingConversation(false);
        }
    }

    async function handleBranchFromMessage(messageId: string | number) {
        if (handle.isStreaming || loadingConversation || !handle.conversationId) {
            return;
        }

        if (typeof messageId !== 'string') {
            return;
        }

        setLoadingConversation(true);

        try {
            const response = await apiJson(
                ConversationController.branch.url(handle.conversationId),
                'POST',
                { message_id: messageId },
            );

            if (!response.ok) {
                throw new Error('Failed to branch conversation');
            }

            const branch: Conversation = await response.json();

            const messagesResponse = await apiFetch(
                ConversationController.messages.url(branch.id),
                { headers: { Accept: 'application/json' } },
            );

            if (!messagesResponse.ok) {
                throw new Error('Failed to load branched conversation');
            }

            const branchMessages: Message[] = await messagesResponse.json();

            handle.setMessages(branchMessages);
            handle.setConversationId(branch.id);
            handle.setActiveProjectId(branch.project_id ?? null);
            handle.nextId.current = branchMessages.length;

            router.reload({ only: ['conversations'] });
        } catch {
            showToast(
                'Failed to branch conversation. Please try again.',
                'error',
            );
        } finally {
            setLoadingConversation(false);
        }
    }

    return {
        loadingConversation,
        loadConversation,
        handleBranchFromMessage,
    };
}
