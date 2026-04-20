import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { ContextMenu } from '@/components/context-menu';
import {
    BranchIcon,
    DownloadIcon,
    FolderIcon,
    PencilIcon,
    PinIcon,
    TrashIcon,
} from '@/components/icons';
import { InlineEditor } from '@/components/inline-editor';
import { showToast } from '@/components/toast';
import { apiJson, apiFetch } from '@/lib/api';
import type { Conversation, Project } from '@/types/chat';

export function ConversationItem({
    convo,
    projects,
    isActive,
    onSelect,
    onRenamed,
    onDeleted,
    onMoved,
    onPinned,
    indent = false,
}: {
    convo: Conversation;
    projects: Project[];
    isActive: boolean;
    onSelect: () => void;
    onRenamed: () => void;
    onDeleted: () => void;
    onMoved: () => void;
    onPinned: () => void;
    indent?: boolean;
}) {
    const [editing, setEditing] = useState(false);

    async function handleRename(title: string) {
        try {
            await apiJson(
                ConversationController.update.url(convo.id),
                'PATCH',
                {
                    title,
                },
            );
            setEditing(false);
            onRenamed();
        } catch {
            showToast('Failed to rename conversation. Please try again.');
        }
    }

    async function handleDelete() {
        try {
            await apiFetch(ConversationController.destroy.url(convo.id), {
                method: 'DELETE',
            });
            onDeleted();
        } catch {
            showToast('Failed to delete conversation. Please try again.');
        }
    }

    async function handleMove(projectId: number | null) {
        try {
            await apiJson(
                ConversationController.update.url(convo.id),
                'PATCH',
                {
                    project_id: projectId,
                },
            );
            onMoved();
        } catch {
            showToast('Failed to move conversation. Please try again.');
        }
    }

    async function handleTogglePin() {
        try {
            await apiJson(
                ConversationController.update.url(convo.id),
                'PATCH',
                {
                    is_pinned: !convo.is_pinned,
                },
            );
            onPinned();
        } catch {
            showToast('Failed to update pin. Please try again.');
        }
    }

    if (editing) {
        return (
            <li>
                <InlineEditor
                    initialValue={convo.title}
                    onSave={handleRename}
                    onCancel={() => setEditing(false)}
                    className={indent ? 'ml-4 px-1' : 'px-1'}
                />
            </li>
        );
    }

    return (
        <li
            className={`group relative rounded-lg transition-colors ${
                isActive
                    ? 'bg-gray-200 dark:bg-surface-350'
                    : 'hover:bg-gray-100 dark:hover:bg-surface-200'
            } ${indent ? 'ml-4' : ''}`}
        >
            <button onClick={onSelect} className="w-full px-3 py-2 text-left">
                <p className="flex items-center gap-1.5 truncate pr-6 text-sm text-gray-700 dark:text-gray-200">
                    {convo.is_pinned && (
                        <span className="shrink-0 text-gray-400 dark:text-gray-500">
                            <PinIcon className="size-3" />
                        </span>
                    )}
                    {convo.parent_id && (
                        <span
                            className="shrink-0 text-gray-400 dark:text-gray-500"
                            title="Branched conversation"
                            aria-label="Branched conversation"
                        >
                            <BranchIcon className="size-3" />
                        </span>
                    )}
                    <span className="truncate">{convo.title}</span>
                </p>
            </button>

            <div className="absolute top-1/2 right-2 -translate-y-1/2">
                <ContextMenu
                    items={[
                        {
                            label: convo.is_pinned ? 'Unpin' : 'Pin',
                            onClick: handleTogglePin,
                            icon: <PinIcon />,
                        },
                        {
                            label: 'Rename',
                            onClick: () => setEditing(true),
                            icon: <PencilIcon />,
                        },
                        {
                            label: 'Move to',
                            icon: <FolderIcon />,
                            submenu: [
                                {
                                    label: 'No project',
                                    onClick: () => handleMove(null),
                                    active: convo.project_id === null,
                                },
                                ...projects.map((p) => ({
                                    label: p.name,
                                    onClick: () => handleMove(p.id),
                                    active: convo.project_id === p.id,
                                })),
                            ],
                        },
                        {
                            label: 'Export',
                            icon: <DownloadIcon />,
                            submenu: [
                                {
                                    label: 'Markdown',
                                    onClick: () =>
                                        window.open(
                                            `/conversations/${convo.id}/export?format=markdown`,
                                        ),
                                },
                                {
                                    label: 'JSON',
                                    onClick: () =>
                                        window.open(
                                            `/conversations/${convo.id}/export?format=json`,
                                        ),
                                },
                            ],
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
        </li>
    );
}
