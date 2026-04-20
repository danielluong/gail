import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Conversation, Project } from '@/types/chat';

// Mock @inertiajs/react before importing Chat
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: () => null,
    router: {
        reload: vi.fn(),
        visit: vi.fn(),
    },
    usePage: () => ({
        props: {
            name: 'Gail',
            auth: { user: null },
            toolLabels: {},
            transcriptionEnabled: false,
            aiProvider: 'ollama',
        },
    }),
}));

vi.mock('@/actions/App/Http/Controllers/ChatController', () => ({
    default: {
        stream: { url: () => '/' },
        models: { url: () => '/models' },
    },
}));

vi.mock('@/actions/App/Http/Controllers/ConversationController', () => ({
    default: {
        messages: { url: () => '/c/messages' },
        branch: { url: () => '/c/branch' },
        search: { url: () => '/c/search' },
        update: { url: () => '/c/update' },
        destroy: { url: () => '/c/destroy' },
        export: { url: () => '/c/export' },
    },
}));

vi.mock('@/actions/App/Http/Controllers/ProjectController', () => ({
    default: {
        store: { url: () => '/projects' },
        update: { url: () => '/projects/x' },
        destroy: { url: () => '/projects/x' },
    },
}));

describe('Chat page', () => {
    beforeEach(() => {
        localStorage.clear();

        // jsdom lacks matchMedia; stub it so theme.ts doesn't crash
        if (!window.matchMedia) {
            window.matchMedia = () =>
                ({
                    matches: false,
                    media: '',
                    onchange: null,
                    addListener: () => {},
                    removeListener: () => {},
                    addEventListener: () => {},
                    removeEventListener: () => {},
                    dispatchEvent: () => false,
                }) as MediaQueryList;
        }

        if (!Element.prototype.scrollIntoView) {
            Element.prototype.scrollIntoView = function () {};
        }
    });

    it('module imports without runtime error', async () => {
        const mod = await import('./chat');
        expect(mod.default).toBeDefined();
    });

    async function renderChatWith(props: {
        projects: Project[];
        conversations: Conversation[];
        storedConversation?: string;
    }) {
        if (props.storedConversation) {
            localStorage.setItem(
                'gail-last-conversation',
                JSON.stringify(props.storedConversation),
            );
        }

        const React = await import('react');
        const { createRoot } = await import('react-dom/client');
        const { default: Chat } = await import('./chat');

        const container = document.createElement('div');
        document.body.appendChild(container);

        const root = createRoot(container);

        let caught: Error | null = null;
        const origError = console.error;
        console.error = (...args) => {
            const err = args.find((a) => a instanceof Error);

            if (err && caught === null) {
                caught = err;
            }
        };

        root.render(
            React.createElement(Chat, {
                projects: props.projects,
                conversations: props.conversations,
                agents: [],
            }),
        );

        await new Promise((resolve) => setTimeout(resolve, 20));

        console.error = origError;
        root.unmount();

        return caught;
    }

    it('renders empty state without crashing', async () => {
        const caught = await renderChatWith({
            projects: [],
            conversations: [],
        });

        if (caught) {
            throw caught;
        }
    });

    it('renders with projects + conversations without crashing', async () => {
        const caught = await renderChatWith({
            projects: [
                { id: 1, name: 'Alpha', system_prompt: null },
                { id: 2, name: 'Beta', system_prompt: 'be beta' },
            ],
            conversations: [
                {
                    id: 'c1',
                    title: 'First',
                    project_id: 1,
                    parent_id: null,
                    is_pinned: true,
                    updated_at: new Date().toISOString(),
                },
                {
                    id: 'c2',
                    title: 'Second',
                    project_id: null,
                    parent_id: null,
                    is_pinned: false,
                    updated_at: new Date().toISOString(),
                },
            ],
        });

        if (caught) {
            throw caught;
        }
    });

    it('renders with a stored conversation id to restore', async () => {
        const caught = await renderChatWith({
            projects: [],
            conversations: [
                {
                    id: 'c1',
                    title: 'Restored',
                    project_id: null,
                    parent_id: null,
                    is_pinned: false,
                    updated_at: new Date().toISOString(),
                },
            ],
            storedConversation: 'c1',
        });

        if (caught) {
            throw caught;
        }
    });
});
