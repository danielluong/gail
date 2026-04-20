import { useEffect, useRef, useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import { CloseIcon } from '@/components/icons';
import { showToast } from '@/components/toast';
import { apiJson } from '@/lib/api';
import type { Project } from '@/types/chat';

export function SystemPromptEditor({
    project,
    onSaved,
    onClose,
}: {
    project: Project;
    onSaved: () => void;
    onClose: () => void;
}) {
    const [value, setValue] = useState(project.system_prompt ?? '');
    const [saving, setSaving] = useState(false);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        textareaRef.current?.focus();
    }, []);

    async function handleSave() {
        setSaving(true);

        try {
            await apiJson(ProjectController.update.url(project.id), 'PATCH', {
                system_prompt: value || null,
            });
            onSaved();
            onClose();
        } catch {
            showToast('Failed to save system prompt.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="mr-1 mb-2 ml-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-surface-300 dark:bg-surface-100">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium text-gray-500">
                    System Prompt
                </span>
                <button
                    type="button"
                    onClick={onClose}
                    className="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                >
                    <CloseIcon className="size-3.5" />
                </button>
            </div>
            <textarea
                ref={textareaRef}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder="e.g. Always respond with TypeScript examples. Focus on Node.js best practices."
                rows={4}
                className="w-full resize-none rounded-md border border-gray-200 bg-gray-50 px-2.5 py-2 text-xs text-gray-700 placeholder-gray-400 focus:border-gray-300 focus:ring-0 focus:outline-none dark:border-surface-500 dark:bg-surface-200 dark:text-gray-200 dark:placeholder-gray-500 dark:focus:border-surface-600"
            />
            <div className="mt-2 flex justify-end">
                <button
                    type="button"
                    onClick={handleSave}
                    disabled={saving}
                    className="rounded-md bg-orange-500 px-3 py-1 text-xs text-white transition-colors hover:bg-orange-600 disabled:opacity-50"
                >
                    {saving ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}
