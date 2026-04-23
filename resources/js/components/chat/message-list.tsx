import { useCallback, useEffect, useRef, useState } from 'react';
import type { Message, Project } from '@/types/chat';
import { AssistantMessage } from './assistant-message';
import { MessageActions } from './message-actions';
import { SuggestedPrompts } from './suggested-prompts';
import { UserMessage } from './user-message';

type Props = {
    messages: Message[];
    isStreaming: boolean;
    isLoading: boolean;
    activeProject: Project | undefined;
    onRegenerate: () => void;
    onSelectPrompt: (prompt: string) => void;
    onEditMessage: (id: string | number, content: string) => void;
    onBranchFromMessage: (id: string | number) => void;
};

export function MessageList({
    messages,
    isStreaming,
    isLoading,
    activeProject,
    onRegenerate,
    onSelectPrompt,
    onEditMessage,
    onBranchFromMessage,
}: Props) {
    const [editingId, setEditingId] = useState<string | number | null>(null);
    const [editDraft, setEditDraft] = useState('');
    const [activeVariantByMessageId, setActiveVariantByMessageId] = useState<
        Record<string, number>
    >({});
    const messagesEndRef = useRef<HTMLDivElement>(null);

    const selectVariant = useCallback(
        (messageId: string | number, index: number) => {
            setActiveVariantByMessageId((prev) => ({
                ...prev,
                [String(messageId)]: index,
            }));
        },
        [],
    );

    /*
     * Latest messages + edit draft are exposed via refs so the stable
     * callbacks below don't need them in their dependency arrays. Adding
     * `messages` or `editDraft` to a useCallback dep list would give the
     * callback a fresh identity on every token, defeating the memo on
     * UserMessage / MessageActions during streaming.
     */
    const messagesRef = useRef(messages);
    const editDraftRef = useRef(editDraft);

    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

    useEffect(() => {
        editDraftRef.current = editDraft;
    }, [editDraft]);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    /*
     * When a new stream starts — new turn, regenerate, or edit — drop
     * any stored carousel selection for the last assistant slot so the
     * new top-level variant becomes the default view once the stream
     * completes. Without this, a regenerate appends a new variant and
     * the stored index (which pointed at the previous latest) now
     * points at a stale older one, which flashes back on-screen the
     * moment isStreaming flips to false.
     */
    useEffect(() => {
        if (!isStreaming) {
            return;
        }

        const lastAssistant = [...messagesRef.current]
            .reverse()
            .find((m) => m.role === 'assistant');

        if (!lastAssistant) {
            return;
        }

        setActiveVariantByMessageId((prev) => {
            const key = String(lastAssistant.id);

            if (!(key in prev)) {
                return prev;
            }

            const { [key]: _, ...rest } = prev;

            return rest;
        });
    }, [isStreaming]);

    const startEdit = useCallback((messageId: string | number) => {
        const target = messagesRef.current.find((m) => m.id === messageId);

        if (target) {
            setEditingId(messageId);
            setEditDraft(target.content);
        }
    }, []);

    const cancelEdit = useCallback(() => {
        setEditingId(null);
        setEditDraft('');
    }, []);

    const submitEdit = useCallback(
        (messageId: string | number) => {
            const draft = editDraftRef.current;
            setEditingId(null);
            setEditDraft('');
            onEditMessage(messageId, draft);
        },
        [onEditMessage],
    );

    return (
        <div className="flex-1 px-3 py-4 md:px-4 md:py-6">
            <div className="mx-auto max-w-2xl space-y-6">
                {isLoading ? (
                    <div className="py-20 text-center text-gray-500">
                        Loading conversation...
                    </div>
                ) : messages.length === 0 ? (
                    <div className="py-20">
                        <SuggestedPrompts
                            activeProject={activeProject}
                            onSelect={onSelectPrompt}
                        />
                    </div>
                ) : (
                    messages.map((message, index) => {
                        const isLast = index === messages.length - 1;
                        const isLastAssistant =
                            message.role === 'assistant' &&
                            isLast &&
                            !isStreaming &&
                            message.content !== '';
                        const isCurrentlyStreaming =
                            isStreaming &&
                            isLast &&
                            message.role === 'assistant';
                        const isEditing = editingId === message.id;

                        /*
                         * Resolve which variant of an assistant slot to
                         * render. The server sends the latest at the
                         * top level and the earlier history in
                         * `variants`, so the total slot count is
                         * `variants.length + 1` and the default active
                         * index is the last one (the top-level).
                         *
                         * While a turn is actively streaming — the
                         * bubble is empty and isStreaming is true —
                         * force display of the latest slot regardless
                         * of any stored carousel selection. Without
                         * this, a regenerate that appends a new
                         * variant leaves the stored index pointing at
                         * an older one, the UI shows that older
                         * variant's content, and ThinkingIndicator
                         * never appears for the new stream.
                         */
                        const variantCount =
                            message.role === 'assistant' && message.variants
                                ? message.variants.length + 1
                                : undefined;
                        const forceLatestVariant =
                            isCurrentlyStreaming && message.content === '';
                        const activeVariantIndex =
                            variantCount !== undefined
                                ? forceLatestVariant
                                    ? variantCount - 1
                                    : (activeVariantByMessageId[
                                          String(message.id)
                                      ] ?? variantCount - 1)
                                : undefined;
                        const isLatestVariantActive =
                            variantCount === undefined ||
                            activeVariantIndex === variantCount - 1;
                        const displayMessage =
                            !isLatestVariantActive &&
                            message.variants &&
                            activeVariantIndex !== undefined
                                ? {
                                      ...message,
                                      content:
                                          message.variants[activeVariantIndex]
                                              .content,
                                      toolCalls:
                                          message.variants[activeVariantIndex]
                                              .toolCalls,
                                      attachments:
                                          message.variants[activeVariantIndex]
                                              .attachments,
                                      model: message.variants[
                                          activeVariantIndex
                                      ].model,
                                      usage: message.variants[
                                          activeVariantIndex
                                      ].usage,
                                      cost: message.variants[
                                          activeVariantIndex
                                      ].cost,
                                      phases: message.variants[
                                          activeVariantIndex
                                      ].phases,
                                      created_at:
                                          message.variants[activeVariantIndex]
                                              .created_at,
                                  }
                                : message;

                        return (
                            <div
                                key={message.id}
                                className={`group/msg flex print:break-inside-avoid ${
                                    message.role === 'user'
                                        ? 'justify-end'
                                        : 'justify-start'
                                }`}
                            >
                                <div
                                    className={
                                        message.role === 'user'
                                            ? 'max-w-[85%] md:max-w-[70%]'
                                            : 'w-full'
                                    }
                                >
                                    {message.role === 'user' ? (
                                        <UserMessage
                                            message={message}
                                            isEditing={isEditing}
                                            editDraft={
                                                isEditing ? editDraft : ''
                                            }
                                            onEditDraftChange={setEditDraft}
                                            onCancelEdit={cancelEdit}
                                            onSubmitEdit={submitEdit}
                                        />
                                    ) : (
                                        <AssistantMessage
                                            message={displayMessage}
                                            isStreaming={isCurrentlyStreaming}
                                        />
                                    )}
                                    {displayMessage.content && (
                                        <MessageActions
                                            message={displayMessage}
                                            isLastAssistant={isLastAssistant}
                                            isEditing={isEditing}
                                            variantIndex={activeVariantIndex}
                                            variantCount={variantCount}
                                            onSelectVariant={selectVariant}
                                            onStartEdit={startEdit}
                                            onBranchFromMessage={
                                                onBranchFromMessage
                                            }
                                            onRegenerate={onRegenerate}
                                        />
                                    )}
                                </div>
                            </div>
                        );
                    })
                )}

                <div ref={messagesEndRef} className="h-24" />
            </div>
        </div>
    );
}
