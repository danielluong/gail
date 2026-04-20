import { PaperclipIcon } from '@/components/icons';
import type { Attachment } from '@/types/chat';

export function AttachmentChip({ attachment }: { attachment: Attachment }) {
    const isImage =
        typeof attachment.url === 'string' &&
        attachment.type?.startsWith('image/');

    if (isImage) {
        return (
            <a
                href={attachment.url}
                target="_blank"
                rel="noreferrer"
                className="block overflow-hidden rounded-lg border border-gray-200 dark:border-white/10"
            >
                <img
                    src={attachment.url}
                    alt={attachment.name}
                    className="max-h-56 max-w-xs object-cover"
                />
            </a>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-md bg-gray-200/60 px-2 py-0.5 text-xs dark:bg-white/10">
            <PaperclipIcon />
            {attachment.name}
        </span>
    );
}
