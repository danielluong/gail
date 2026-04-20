import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { AgentOption } from '@/components/chat/agent-selector';
import { ChatInput } from '@/components/chat/chat-input';
import { MessageList } from '@/components/chat/message-list';
import { ErrorBoundary } from '@/components/error-boundary';
import { NewChatIcon, SidebarIcon } from '@/components/icons';
import { Sidebar } from '@/components/sidebar/sidebar';
import { ToastContainer } from '@/components/toast';
import { useChat } from '@/hooks/use-chat';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { formatCost } from '@/lib/numbers';
import { getStored, setStored } from '@/lib/storage';
import type { Conversation, Message, Project } from '@/types/chat';

const SIDEBAR_STORAGE_KEY = 'gail-sidebar-open';
const MOBILE_BREAKPOINT = 768;

function isMobileViewport() {
    return typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAKPOINT;
}

export default function Chat(props: {
    projects: Project[];
    conversations: Conversation[];
    agents: AgentOption[];
}) {
    return (
        <ErrorBoundary>
            <ChatPage {...props} />
        </ErrorBoundary>
    );
}

/*
 * Sum the per-message cost field, returning null when no message has a
 * priced cost — so the header hides the badge entirely rather than
 * claiming $0.00 for an all-local (Ollama) conversation.
 */
function sumMessageCost(messages: Message[]): number | null {
    let total = 0;
    let hasAny = false;

    for (const message of messages) {
        if (typeof message.cost === 'number') {
            total += message.cost;
            hasAny = true;
        }
    }

    return hasAny ? total : null;
}

function ChatPage({
    projects,
    conversations: initialConversations,
    agents,
}: {
    projects: Project[];
    conversations: Conversation[];
    agents: AgentOption[];
}) {
    const [sidebarOpen, setSidebarOpen] = useState(() => {
        if (isMobileViewport()) {
            return false;
        }

        return getStored(SIDEBAR_STORAGE_KEY, true);
    });

    useEffect(() => {
        if (isMobileViewport()) {
            return;
        }

        setStored(SIDEBAR_STORAGE_KEY, sidebarOpen);
    }, [sidebarOpen]);

    const [isScrolled, setIsScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setIsScrolled(window.scrollY > 0);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const searchInputRef = useRef<HTMLInputElement>(null);
    const chatInputRef = useRef<HTMLTextAreaElement>(null);

    const {
        conversations,
        messages,
        input,
        setInput,
        isStreaming,
        conversationId,
        activeProjectId,
        loadingConversation,
        model,
        setModel,
        temperature,
        setTemperature,
        agent,
        setAgent,
        startNewChat,
        loadConversation,
        handleConversationDeleted,
        handleSubmit,
        handleStop,
        handleRegenerate,
        handleEditMessage,
        handleBranchFromMessage,
    } = useChat(initialConversations);

    const closeSidebarOnMobile = useCallback(() => {
        if (isMobileViewport()) {
            setSidebarOpen(false);
        }
    }, []);

    const handleSelectConversation = useCallback(
        (id: string) => {
            loadConversation(id);
            closeSidebarOnMobile();
        },
        [loadConversation, closeSidebarOnMobile],
    );

    const handleStartNewChat = useCallback(
        (projectId?: number | null) => {
            startNewChat(projectId);
            closeSidebarOnMobile();
        },
        [startNewChat, closeSidebarOnMobile],
    );

    const shortcuts = useMemo(
        () => [
            {
                key: 'o',
                meta: true,
                shift: true,
                handler: () => startNewChat(),
            },
            {
                key: 's',
                meta: true,
                shift: true,
                handler: () => setSidebarOpen((prev) => !prev),
            },
            {
                key: 'k',
                meta: true,
                handler: () => {
                    if (!sidebarOpen) {
                        setSidebarOpen(true);
                    }

                    requestAnimationFrame(() =>
                        searchInputRef.current?.focus(),
                    );
                },
            },
            {
                key: '/',
                handler: () => chatInputRef.current?.focus(),
            },
        ],
        [sidebarOpen, startNewChat],
    );

    useKeyboardShortcuts(shortcuts);

    const activeProject = projects.find((p) => p.id === activeProjectId);
    const conversationCost = useMemo(
        () => sumMessageCost(messages),
        [messages],
    );

    return (
        <>
            <Head title="Chat" />

            <div className="relative flex min-h-dvh bg-white dark:bg-surface-150">
                {sidebarOpen && (
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(false)}
                        className="fixed inset-0 z-30 bg-black/40 md:hidden print:hidden"
                        aria-label="Close sidebar"
                    />
                )}

                <Sidebar
                    projects={projects}
                    conversations={conversations}
                    conversationId={conversationId}
                    activeProjectId={activeProjectId}
                    sidebarOpen={sidebarOpen}
                    onToggleSidebar={() => setSidebarOpen((prev) => !prev)}
                    onNewChat={handleStartNewChat}
                    onSelectConversation={handleSelectConversation}
                    onConversationDeleted={handleConversationDeleted}
                    searchInputRef={searchInputRef}
                />

                <div className="flex min-w-0 flex-1 flex-col">
                    <header
                        className={`sticky top-0 z-20 flex items-center gap-2 border-b bg-white px-3 py-3 transition-colors md:gap-3 md:px-6 md:py-4 dark:bg-surface-150 ${
                            isScrolled
                                ? 'border-gray-200 dark:border-surface-300'
                                : 'border-transparent'
                        }`}
                    >
                        {!sidebarOpen && (
                            <button
                                type="button"
                                onClick={() => setSidebarOpen(true)}
                                className="-ml-1 rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 md:hidden print:hidden dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200"
                                aria-label="Open sidebar"
                            >
                                <SidebarIcon />
                            </button>
                        )}
                        <h1 className="truncate text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {activeProject ? activeProject.name : 'Chat'}
                        </h1>
                        {conversationCost !== null && (
                            <span
                                className="text-xs text-gray-400 dark:text-gray-500"
                                title="Running cost across priced messages in this conversation"
                            >
                                {formatCost(conversationCost)}
                            </span>
                        )}
                        {!sidebarOpen && (
                            <button
                                type="button"
                                onClick={() => handleStartNewChat()}
                                className="ml-auto rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 md:hidden print:hidden dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200"
                                aria-label="New chat"
                            >
                                <NewChatIcon />
                            </button>
                        )}
                    </header>

                    <MessageList
                        messages={messages}
                        isStreaming={isStreaming}
                        isLoading={loadingConversation}
                        activeProject={activeProject}
                        onRegenerate={handleRegenerate}
                        onEditMessage={handleEditMessage}
                        onBranchFromMessage={handleBranchFromMessage}
                        onSelectPrompt={(prompt) => {
                            setInput(prompt);
                            requestAnimationFrame(() => {
                                const textarea = chatInputRef.current;
                                if (textarea) {
                                    textarea.focus();
                                    textarea.selectionStart = textarea.value.length;
                                    textarea.selectionEnd = textarea.value.length;
                                }
                            });
                        }}
                    />

                    <ChatInput
                        value={input}
                        onChange={setInput}
                        onSubmit={handleSubmit}
                        onStop={handleStop}
                        disabled={isStreaming}
                        streaming={isStreaming}
                        model={model}
                        onModelChange={setModel}
                        agent={agent}
                        onAgentChange={setAgent}
                        agents={agents}
                        temperature={temperature}
                        onTemperatureChange={setTemperature}
                        textareaRef={chatInputRef}
                    />
                </div>
            </div>

            <ToastContainer />
        </>
    );
}
