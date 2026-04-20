import { router } from '@inertiajs/react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import { ContextMenu } from '@/components/context-menu';
import {
    ChevronRightIcon,
    FolderIcon,
    PencilIcon,
    PlusIcon,
    SettingsIcon,
    TrashIcon,
} from '@/components/icons';
import { InlineEditor } from '@/components/inline-editor';
import { showToast } from '@/components/toast';
import { apiFetch, apiJson } from '@/lib/api';
import type { Conversation, Project } from '@/types/chat';
import { ConversationList } from './conversation-list';
import { SystemPromptEditor } from './system-prompt-editor';

export function ProjectSection({
    project,
    projects,
    conversations,
    activeConversationId,
    onSelectConversation,
    onNewChat,
    onRenamed,
    onDeleted,
    onConversationDeleted,
}: {
    project: Project;
    projects: Project[];
    conversations: Conversation[];
    activeConversationId: string | null;
    onSelectConversation: (id: string) => void;
    onNewChat: () => void;
    onRenamed: () => void;
    onDeleted: () => void;
    onConversationDeleted: (id: string) => void;
}) {
    const [expanded, setExpanded] = useState(true);
    const [editing, setEditing] = useState(false);
    const [showSettings, setShowSettings] = useState(false);

    async function handleRename(name: string) {
        try {
            await apiJson(ProjectController.update.url(project.id), 'PATCH', {
                name,
            });
            setEditing(false);
            onRenamed();
        } catch {
            showToast('Failed to rename project. Please try again.');
        }
    }

    async function handleDelete() {
        if (
            !confirm(
                `Delete project "${project.name}" and all its conversations?`,
            )
        ) {
            return;
        }

        try {
            await apiFetch(ProjectController.destroy.url(project.id), {
                method: 'DELETE',
            });
            onDeleted();
        } catch {
            showToast('Failed to delete project. Please try again.');
        }
    }

    if (editing) {
        return (
            <li>
                <InlineEditor
                    initialValue={project.name}
                    onSave={handleRename}
                    onCancel={() => setEditing(false)}
                    className="px-1"
                />
            </li>
        );
    }

    return (
        <li>
            <div className="group relative rounded-lg transition-colors hover:bg-gray-100 dark:hover:bg-surface-250">
                <button
                    onClick={() => setExpanded(!expanded)}
                    className="flex w-full items-center gap-1.5 px-2 py-2 text-left"
                >
                    <ChevronRightIcon
                        className={`size-3 text-gray-500 transition-transform ${expanded ? 'rotate-90' : ''}`}
                    />
                    <span className="text-gray-500 dark:text-gray-400">
                        <FolderIcon />
                    </span>
                    <span className="flex-1 truncate pr-6 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {project.name}
                    </span>
                </button>

                <div className="absolute top-1/2 right-2 -translate-y-1/2">
                    <ContextMenu
                        items={[
                            {
                                label: 'New chat',
                                onClick: onNewChat,
                                icon: <PlusIcon />,
                            },
                            {
                                label: 'Settings',
                                onClick: () => setShowSettings(true),
                                icon: <SettingsIcon />,
                            },
                            {
                                label: 'Rename',
                                onClick: () => setEditing(true),
                                icon: <PencilIcon />,
                            },
                            {
                                label: 'Delete',
                                onClick: handleDelete,
                                danger: true,
                                icon: <TrashIcon />,
                            },
                        ]}
                    />
                </div>
            </div>

            {showSettings && (
                <SystemPromptEditor
                    project={project}
                    onSaved={() => router.reload({ only: ['projects'] })}
                    onClose={() => setShowSettings(false)}
                />
            )}

            {expanded && (
                <div className="mt-0.5">
                    {conversations.length === 0 ? (
                        <p className="ml-4 px-3 py-1.5 text-xs text-gray-500">
                            No chats
                        </p>
                    ) : (
                        <ConversationList
                            conversations={conversations}
                            projects={projects}
                            activeConversationId={activeConversationId}
                            onSelect={onSelectConversation}
                            onDeleted={onConversationDeleted}
                            indent
                        />
                    )}
                </div>
            )}
        </li>
    );
}
