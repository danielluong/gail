import { Link, router } from '@inertiajs/react';
import type { RefObject } from 'react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import {
    ChartIcon,
    NewChatIcon,
    PlusIcon,
    SidebarIcon,
} from '@/components/icons';
import { InlineEditor } from '@/components/inline-editor';
import { ThemeToggle } from '@/components/theme-toggle';
import { showToast } from '@/components/toast';
import { useConversationSearch } from '@/hooks/use-conversation-search';
import { apiJson } from '@/lib/api';
import { groupConversationsByDate } from '@/lib/dates';
import type { Conversation, Project } from '@/types/chat';
import { ConversationList } from './conversation-list';
import { ProjectSection } from './project-section';
import { SidebarSearch } from './sidebar-search';

export function Sidebar({
    projects,
    conversations,
    conversationId,
    activeProjectId,
    sidebarOpen,
    onToggleSidebar,
    onNewChat,
    onSelectConversation,
    onConversationDeleted,
    searchInputRef,
}: {
    projects: Project[];
    conversations: Conversation[];
    conversationId: string | null;
    activeProjectId: number | null;
    sidebarOpen: boolean;
    onToggleSidebar: () => void;
    onNewChat: (projectId?: number | null) => void;
    onSelectConversation: (id: string) => void;
    onConversationDeleted: (id: string) => void;
    searchInputRef?: RefObject<HTMLInputElement | null>;
}) {
    const [creatingProject, setCreatingProject] = useState(false);
    const search = useConversationSearch();

    const projectConversations = (projectId: number) =>
        conversations.filter((c) => c.project_id === projectId);
    const looseConversations = conversations.filter(
        (c) => c.project_id === null,
    );
    const pinnedLoose = looseConversations.filter((c) => c.is_pinned);
    const unpinnedLoose = looseConversations.filter((c) => !c.is_pinned);

    async function handleCreateProject(name: string) {
        try {
            await apiJson(ProjectController.store.url(), 'POST', { name });
            setCreatingProject(false);
            router.reload({ only: ['projects'] });
        } catch {
            showToast('Failed to create project. Please try again.');
        }
    }

    function handleSelectSearchResult(id: string) {
        search.clear();
        onSelectConversation(id);
    }

    return (
        <div
            className={`fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-gray-50 transition-transform duration-200 md:sticky md:top-0 md:h-dvh md:self-start md:z-auto md:transition-all print:hidden dark:bg-surface-50 ${
                sidebarOpen
                    ? 'translate-x-0 md:w-72'
                    : '-translate-x-full md:w-16 md:translate-x-0'
            }`}
        >
            <div className="flex items-center gap-2 px-3 py-3">
                <button
                    onClick={onToggleSidebar}
                    className="rounded-lg p-2 text-gray-500 transition-colors hover:cursor-ew-resize hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200"
                    aria-label="Toggle sidebar"
                >
                    <SidebarIcon />
                </button>
                <button
                    onClick={() => onNewChat()}
                    className={`rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200 ${sidebarOpen ? 'ml-auto' : 'hidden'}`}
                    aria-label="New chat"
                >
                    <NewChatIcon />
                </button>
            </div>

            {!sidebarOpen && (
                <div className="flex flex-1 flex-col items-center px-3 pt-2">
                    <button
                        onClick={() => onNewChat()}
                        className="rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200"
                        aria-label="New chat"
                    >
                        <NewChatIcon />
                    </button>
                </div>
            )}

            {sidebarOpen && (
                <div className="flex flex-1 flex-col overflow-hidden px-2">
                    <SidebarSearch
                        value={search.query}
                        onChange={search.setQuery}
                        onClear={search.clear}
                        inputRef={searchInputRef}
                    />

                    <div className="flex-1 overflow-y-auto">
                        {search.isActive ? (
                            <SearchResults
                                searching={search.searching}
                                results={search.results}
                                projects={projects}
                                conversationId={conversationId}
                                onSelect={handleSelectSearchResult}
                                onConversationDeleted={onConversationDeleted}
                            />
                        ) : (
                            <>
                                <div className="mb-3">
                                    <div className="flex items-center justify-between px-2 py-2">
                                        <span className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                            Projects
                                        </span>
                                        <button
                                            onClick={() =>
                                                setCreatingProject(true)
                                            }
                                            className="rounded p-1 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-surface-250 dark:hover:text-gray-300"
                                            aria-label="New project"
                                        >
                                            <PlusIcon
                                                className="size-3.5"
                                                strokeWidth={2}
                                            />
                                        </button>
                                    </div>

                                    <ul className="space-y-0.5">
                                        {creatingProject && (
                                            <li>
                                                <InlineEditor
                                                    initialValue=""
                                                    onSave={handleCreateProject}
                                                    onCancel={() =>
                                                        setCreatingProject(
                                                            false,
                                                        )
                                                    }
                                                    className="px-1"
                                                />
                                            </li>
                                        )}
                                        {projects.map((project) => (
                                            <ProjectSection
                                                key={project.id}
                                                project={project}
                                                projects={projects}
                                                conversations={projectConversations(
                                                    project.id,
                                                )}
                                                activeConversationId={
                                                    conversationId
                                                }
                                                onSelectConversation={
                                                    onSelectConversation
                                                }
                                                onNewChat={() =>
                                                    onNewChat(project.id)
                                                }
                                                onRenamed={() =>
                                                    router.reload({
                                                        only: ['projects'],
                                                    })
                                                }
                                                onDeleted={() => {
                                                    router.reload({
                                                        only: [
                                                            'projects',
                                                            'conversations',
                                                        ],
                                                    });

                                                    if (
                                                        activeProjectId ===
                                                        project.id
                                                    ) {
                                                        onNewChat();
                                                    }
                                                }}
                                                onConversationDeleted={
                                                    onConversationDeleted
                                                }
                                            />
                                        ))}
                                    </ul>
                                </div>

                                {pinnedLoose.length > 0 && (
                                    <div className="mb-2">
                                        <div className="px-2 py-2">
                                            <span className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                                Pinned
                                            </span>
                                        </div>
                                        <ConversationList
                                            conversations={pinnedLoose}
                                            projects={projects}
                                            activeConversationId={
                                                conversationId
                                            }
                                            onSelect={onSelectConversation}
                                            onDeleted={onConversationDeleted}
                                        />
                                    </div>
                                )}

                                {groupConversationsByDate(unpinnedLoose).map(
                                    (group) => (
                                        <div key={group.label} className="mb-2">
                                            <div className="px-2 py-2">
                                                <span className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                                    {group.label}
                                                </span>
                                            </div>
                                            <ConversationList
                                                conversations={group.items}
                                                projects={projects}
                                                activeConversationId={
                                                    conversationId
                                                }
                                                onSelect={onSelectConversation}
                                                onDeleted={
                                                    onConversationDeleted
                                                }
                                            />
                                        </div>
                                    ),
                                )}

                                {projects.length === 0 &&
                                    looseConversations.length === 0 && (
                                        <p className="px-2 py-6 text-center text-xs text-gray-500">
                                            No conversations yet.
                                        </p>
                                    )}
                            </>
                        )}
                    </div>
                </div>
            )}

            <div
                className={`flex items-center gap-1 border-t border-gray-200 px-3 py-2 dark:border-surface-250 ${sidebarOpen ? '' : 'flex-col justify-center'}`}
            >
                <ThemeToggle />
                <Link
                    href="/analytics"
                    className="rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-surface-250 dark:hover:text-gray-200"
                    aria-label="Usage analytics"
                    title="Usage analytics"
                >
                    <ChartIcon />
                </Link>
            </div>
        </div>
    );
}

function SearchResults({
    searching,
    results,
    projects,
    conversationId,
    onSelect,
    onConversationDeleted,
}: {
    searching: boolean;
    results: Conversation[] | null;
    projects: Project[];
    conversationId: string | null;
    onSelect: (id: string) => void;
    onConversationDeleted: (id: string) => void;
}) {
    return (
        <div>
            <div className="px-2 py-2">
                <span className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
                    {searching
                        ? 'Searching...'
                        : `Results (${results?.length ?? 0})`}
                </span>
            </div>
            {!searching && results && (
                <>
                    {results.length === 0 ? (
                        <p className="px-3 py-4 text-center text-xs text-gray-500">
                            No conversations found.
                        </p>
                    ) : (
                        <ConversationList
                            conversations={results}
                            projects={projects}
                            activeConversationId={conversationId}
                            onSelect={onSelect}
                            onDeleted={onConversationDeleted}
                        />
                    )}
                </>
            )}
        </div>
    );
}
