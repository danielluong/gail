import { useState } from 'react';
import ChatController from '@/actions/App/Http/Controllers/ChatController';
import { apiFetch } from '@/lib/api';
import type { Attachment } from '@/types/chat';

/**
 * Upload files to the chat upload endpoint, tracking in-flight state
 * and collecting the resulting attachments. Failures for individual
 * files are swallowed silently and the file is dropped from the batch.
 */
export function useFileUpload() {
    const [attachments, setAttachments] = useState<Attachment[]>([]);
    const [uploading, setUploading] = useState(false);

    async function uploadOne(file: File): Promise<Attachment | null> {
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await apiFetch(ChatController.upload.url(), {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData,
            });

            if (!response.ok) {
                return null;
            }

            return await response.json();
        } catch {
            return null;
        }
    }

    async function uploadFiles(files: FileList | File[]) {
        setUploading(true);

        const results = await Promise.all(
            Array.from(files).map((f) => uploadOne(f)),
        );

        const uploaded = results.filter((r): r is Attachment => r !== null);

        setAttachments((prev) => [...prev, ...uploaded]);
        setUploading(false);
    }

    function removeAttachment(index: number) {
        setAttachments((prev) => prev.filter((_, i) => i !== index));
    }

    function clear() {
        setAttachments([]);
    }

    return { attachments, uploading, uploadFiles, removeAttachment, clear };
}
