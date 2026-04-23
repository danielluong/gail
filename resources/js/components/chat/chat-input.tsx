import type { FormEvent, RefObject } from 'react';
import { useRef, useState } from 'react';
import { SendIcon, StopIcon } from '@/components/icons';
import { useAudioRecorder } from '@/hooks/use-audio-recorder';
import { useFileUpload } from '@/hooks/use-file-upload';
import { useSharedProps } from '@/hooks/use-shared-props';
import type { Attachment } from '@/types/chat';
import { AgentSelector, type AgentOption } from './agent-selector';
import { AttachmentTray } from './attachment-tray';
import { ModelSelector } from './model-selector';
import { SettingsPanel } from './settings-panel';

const MAX_TEXTAREA_HEIGHT = 200;

export function ChatInput({
    value,
    onChange,
    onSubmit,
    onStop,
    disabled,
    streaming,
    model,
    onModelChange,
    agent,
    onAgentChange,
    agents,
    temperature,
    onTemperatureChange,
    textareaRef,
}: {
    value: string;
    onChange: (value: string) => void;
    onSubmit: (attachments: Attachment[]) => void;
    onStop: () => void;
    disabled: boolean;
    streaming: boolean;
    model: string;
    onModelChange: (model: string) => void;
    agent: string;
    onAgentChange: (agent: string) => void;
    agents: AgentOption[];
    temperature: number;
    onTemperatureChange: (value: number) => void;
    textareaRef?: RefObject<HTMLTextAreaElement | null>;
}) {
    const internalRef = useRef<HTMLTextAreaElement>(null);
    const inputRef = textareaRef ?? internalRef;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);
    const { transcriptionEnabled, aiProvider } = useSharedProps();
    const { attachments, uploading, uploadFiles, removeAttachment, clear } =
        useFileUpload();
    const recorder = useAudioRecorder((text) => {
        const next = value ? `${value.replace(/\s+$/, '')} ${text}` : text;
        onChange(next);
        requestAnimationFrame(() => inputRef.current?.focus());
    });

    function toggleRecording() {
        if (recorder.status === 'recording') {
            recorder.stop();
        } else if (recorder.status === 'idle') {
            recorder.start();
        }
    }

    async function handleFiles(files: FileList | File[]) {
        await uploadFiles(files);
        inputRef.current?.focus();
    }

    function submit() {
        if (uploading) {
            return;
        }

        onSubmit(attachments);
        clear();

        if (inputRef.current) {
            inputRef.current.style.height = 'auto';
        }
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        submit();
        requestAnimationFrame(() => inputRef.current?.focus());
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    }

    function handlePaste(e: React.ClipboardEvent) {
        const files = Array.from(e.clipboardData.files);

        if (files.length > 0) {
            e.preventDefault();
            handleFiles(files);
        }
    }

    return (
        <div className="sticky bottom-0 z-10 bg-white px-3 pb-[max(1rem,env(safe-area-inset-bottom))] md:px-4 md:pb-6 print:hidden dark:bg-surface-150">
            <form
                onSubmit={handleSubmit}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragOver(false);

                    if (e.dataTransfer.files.length > 0) {
                        handleFiles(e.dataTransfer.files);
                    }
                }}
                className={`mx-auto max-w-2xl rounded-2xl border bg-white transition-colors focus-within:border-orange-400 dark:bg-surface-250 dark:focus-within:border-orange-500/60 ${
                    dragOver
                        ? 'border-orange-400 dark:border-orange-500/60'
                        : 'border-gray-200 dark:border-surface-500'
                }`}
            >
                <AttachmentTray
                    attachments={attachments}
                    uploading={uploading}
                    onRemove={removeAttachment}
                />

                <textarea
                    ref={inputRef}
                    value={value}
                    onChange={(e) => {
                        onChange(e.target.value);
                        e.target.style.height = 'auto';
                        e.target.style.height = `${Math.min(e.target.scrollHeight, MAX_TEXTAREA_HEIGHT)}px`;
                    }}
                    onKeyDown={handleKeyDown}
                    onPaste={handlePaste}
                    placeholder={dragOver ? 'Drop files here...' : 'Message...'}
                    rows={1}
                    className="block max-h-50 w-full resize-none border-0 bg-transparent px-4 pt-3 pb-2 text-sm leading-6 text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none dark:text-gray-100 dark:placeholder-gray-500"
                    autoFocus
                />
                <div className="flex items-center justify-between gap-2 px-3 pb-3">
                    <div className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="rounded-lg p-1 text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                            aria-label="Attach file"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth={1.5}
                                stroke="currentColor"
                                className="size-5"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"
                                />
                            </svg>
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            className="hidden"
                            onChange={(e) => {
                                if (
                                    e.target.files &&
                                    e.target.files.length > 0
                                ) {
                                    handleFiles(e.target.files);
                                    e.target.value = '';
                                }
                            }}
                        />
                        {transcriptionEnabled && (
                            <button
                                type="button"
                                onClick={toggleRecording}
                                disabled={recorder.status === 'transcribing'}
                                className={`rounded-lg p-1 transition-colors disabled:opacity-50 ${
                                    recorder.status === 'recording'
                                        ? 'text-red-500 hover:text-red-600'
                                        : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300'
                                }`}
                                aria-label={
                                    recorder.status === 'recording'
                                        ? 'Stop recording'
                                        : 'Record audio'
                                }
                                title={
                                    recorder.status === 'transcribing'
                                        ? 'Transcribing...'
                                        : recorder.status === 'recording'
                                          ? 'Stop recording'
                                          : 'Record audio'
                                }
                            >
                                {recorder.status === 'recording' ? (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                        className="size-5"
                                    >
                                        <rect
                                            x="7"
                                            y="7"
                                            width="10"
                                            height="10"
                                            rx="1.5"
                                        />
                                    </svg>
                                ) : (
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        strokeWidth={1.5}
                                        stroke="currentColor"
                                        className="size-5"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"
                                        />
                                    </svg>
                                )}
                            </button>
                        )}
                        <AgentSelector
                            value={agent}
                            onChange={onAgentChange}
                            options={agents}
                            disabled={disabled}
                        />
                        <ModelSelector
                            value={model}
                            onChange={onModelChange}
                            disabled={disabled}
                        />
                        {aiProvider === 'ollama' && (
                            <SettingsPanel
                                temperature={temperature}
                                onTemperatureChange={onTemperatureChange}
                                disabled={disabled}
                            />
                        )}
                    </div>
                    {streaming ? (
                        <button
                            type="button"
                            onClick={onStop}
                            aria-label="Stop"
                            className="rounded-xl bg-orange-500 p-1.5 text-white transition-colors hover:bg-orange-600"
                        >
                            <StopIcon />
                        </button>
                    ) : (
                        <button
                            type="submit"
                            disabled={
                                disabled ||
                                uploading ||
                                (!value.trim() && attachments.length === 0)
                            }
                            aria-label="Send"
                            className="rounded-xl bg-orange-500 p-1.5 text-white transition-colors hover:bg-orange-600 disabled:opacity-30"
                        >
                            <SendIcon />
                        </button>
                    )}
                </div>
            </form>
        </div>
    );
}
