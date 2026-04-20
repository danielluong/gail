import type { Project } from '@/types/chat';

type SuggestedPrompt = {
    title: string;
    subtitle: string;
    prompt: string;
};

const DEFAULT_PROMPTS: SuggestedPrompt[] = [
    {
        title: 'Explain a concept',
        subtitle: 'Break down a topic step by step',
        prompt: 'Explain how ',
    },
    {
        title: 'Help me write code',
        subtitle: 'Generate a snippet or function',
        prompt: 'Write a function that ',
    },
    {
        title: 'Summarize text',
        subtitle: 'Condense an article or document',
        prompt: 'Summarize the following text:\n\n',
    },
    {
        title: 'Debug an error',
        subtitle: 'Diagnose what went wrong',
        prompt: 'I am getting this error:\n\n',
    },
];

const PROJECT_PROMPTS: SuggestedPrompt[] = [
    {
        title: 'Continue where I left off',
        subtitle: 'Pick up from previous work',
        prompt: 'Based on the project notes, what should I work on next?',
    },
    {
        title: 'Review project context',
        subtitle: 'See what is saved for this project',
        prompt: 'List the saved notes for this project.',
    },
    {
        title: 'Brainstorm ideas',
        subtitle: 'Generate options for the project',
        prompt: 'Brainstorm three ideas for ',
    },
    {
        title: 'Summarize progress',
        subtitle: 'Recap recent decisions',
        prompt: 'Summarize what we have decided so far in this project.',
    },
];

export function SuggestedPrompts({
    activeProject,
    onSelect,
}: {
    activeProject: Project | undefined;
    onSelect: (prompt: string) => void;
}) {
    const prompts = activeProject ? PROJECT_PROMPTS : DEFAULT_PROMPTS;

    return (
        <div className="mx-auto max-w-2xl px-4">
            <p className="mb-4 text-center text-sm text-gray-500">
                {activeProject
                    ? `New chat in "${activeProject.name}"`
                    : 'How can I help you today?'}
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {prompts.map((p) => (
                    <button
                        key={p.title}
                        type="button"
                        onClick={() => onSelect(p.prompt)}
                        className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-left transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-surface-500 dark:bg-surface-250 dark:hover:border-surface-600 dark:hover:bg-surface-400"
                    >
                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {p.title}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {p.subtitle}
                        </p>
                    </button>
                ))}
            </div>
        </div>
    );
}
