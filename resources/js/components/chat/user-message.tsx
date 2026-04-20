import { memo } from 'react';
import type { Message } from '@/types/chat';
import { AttachmentChip } from './attachment-chip';

type Props = {
    message: Message;
    isEditing: boolean;
    editDraft: string;
    onEditDraftChange: (draft: string) => void;
    onCancelEdit: () => void;
    onSubmitEdit: (messageId: string | number) => void;
};

function UserMessageImpl({
    message,
    isEditing,
    editDraft,
    onEditDraftChange,
    onCancelEdit,
    onSubmitEdit,
}: Props) {
    return (
        <div
            className={`rounded-2xl px-4 py-2.5 ${
                message.error
                    ? 'border border-red-300 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200'
                    : 'bg-gray-100 text-gray-900 dark:bg-surface-250 dark:text-gray-100'
            }`}
        >
            {message.attachments && message.attachments.length > 0 && (
                <div className="mb-1.5 flex flex-wrap gap-1.5">
                    {message.attachments.map((att, i) => (
                        <AttachmentChip key={i} attachment={att} />
                    ))}
                </div>
            )}
            {isEditing ? (
                <div className="flex flex-col gap-2">
                    <textarea
                        value={editDraft}
                        onChange={(e) => onEditDraftChange(e.target.value)}
                        autoFocus
                        rows={3}
                        className="w-full resize-none rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-orange-400 focus:ring-0 focus:outline-none dark:border-surface-600 dark:bg-surface-100 dark:text-gray-100 dark:focus:border-orange-500/60"
                    />
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onCancelEdit}
                            className="rounded-md px-3 py-1 text-xs text-gray-600 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-surface-400"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={() => onSubmitEdit(message.id)}
                            disabled={!editDraft.trim()}
                            className="rounded-md bg-orange-500 px-3 py-1 text-xs text-white hover:bg-orange-600 disabled:opacity-50"
                        >
                            Save &amp; submit
                        </button>
                    </div>
                </div>
            ) : (
                message.content && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap">
                        {message.content}
                    </p>
                )
            )}
        </div>
    );
}

/**
 * Memoized so user messages skip re-render during streaming. The parent
 * holds edit state; non-editing rows are given stable defaults for the
 * editing props, so shallow comparison succeeds for every user row that
 * isn't the one being edited.
 */
export const UserMessage = memo(UserMessageImpl);
