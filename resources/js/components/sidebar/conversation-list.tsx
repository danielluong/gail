import { router } from '@inertiajs/react';
import type { Conversation, Project } from '@/types/chat';
import { ConversationItem } from './conversation-item';

function reloadConversations() {
    router.reload({ only: ['conversations'] });
}

/**
 * Renders a flat list of conversation rows. All the per-item callbacks
 * (rename / move / pin) simply reload conversations from the server,
 * which matches what every sidebar list needs. Delete is explicit so
 * the parent can clear chat state if the active conversation is gone.
 */
export function ConversationList({
    conversations,
    projects,
    activeConversationId,
    onSelect,
    onDeleted,
    indent = false,
}: {
    conversations: Conversation[];
    projects: Project[];
    activeConversationId: string | null;
    onSelect: (id: string) => void;
    onDeleted: (id: string) => void;
    indent?: boolean;
}) {
    return (
        <ul className="space-y-0.5">
            {conversations.map((convo) => (
                <ConversationItem
                    key={convo.id}
                    convo={convo}
                    projects={projects}
                    isActive={activeConversationId === convo.id}
                    onSelect={() => onSelect(convo.id)}
                    onRenamed={reloadConversations}
                    onDeleted={() => onDeleted(convo.id)}
                    onMoved={reloadConversations}
                    onPinned={reloadConversations}
                    indent={indent}
                />
            ))}
        </ul>
    );
}
