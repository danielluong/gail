import { CloseIcon, LoadingSpinner } from '@/components/icons';
import type { Attachment } from '@/types/chat';

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    if (kb < 1024) {
        return `${Math.round(kb)} KB`;
    }

    return `${(kb / 1024).toFixed(1)} MB`;
}

function ImageIcon() {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={1.5}
            stroke="currentColor"
            className="size-3.5 text-gray-400"
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"
            />
        </svg>
    );
}

function DocumentIcon() {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={1.5}
            stroke="currentColor"
            className="size-3.5 text-gray-400"
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
            />
        </svg>
    );
}

function UploadingSpinner() {
    return (
        <div className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs text-gray-400">
            <LoadingSpinner />
            Uploading...
        </div>
    );
}

export function AttachmentTray({
    attachments,
    uploading,
    onRemove,
}: {
    attachments: Attachment[];
    uploading: boolean;
    onRemove: (index: number) => void;
}) {
    if (attachments.length === 0 && !uploading) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2 px-4 pt-3">
            {attachments.map((att, i) => {
                const isImage =
                    typeof att.url === 'string' &&
                    att.type.startsWith('image/');

                return (
                    <div
                        key={i}
                        className="group/att relative flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs dark:border-surface-500 dark:bg-surface-100"
                    >
                        {isImage ? (
                            <img
                                src={att.url}
                                alt={att.name}
                                className="size-8 rounded object-cover"
                            />
                        ) : att.type.startsWith('image/') ? (
                            <ImageIcon />
                        ) : (
                            <DocumentIcon />
                        )}
                        <span className="max-w-32 truncate text-gray-700 dark:text-gray-300">
                            {att.name}
                        </span>
                        {att.size !== undefined && (
                            <span className="text-gray-400 dark:text-gray-500">
                                {formatSize(att.size)}
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={() => onRemove(i)}
                            aria-label={`Remove ${att.name}`}
                            className="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                        >
                            <CloseIcon />
                        </button>
                    </div>
                );
            })}
            {uploading && <UploadingSpinner />}
        </div>
    );
}
