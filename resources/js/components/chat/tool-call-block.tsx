import { useState } from 'react';
import { ChevronRightIcon, LoadingSpinner } from '@/components/icons';
import { useSharedProps } from '@/hooks/use-shared-props';
import type { ToolCall } from '@/types/chat';

function formatArguments(args: Record<string, unknown>): string {
    return Object.entries(args)
        .map(([key, value]) => {
            const str =
                typeof value === 'string' ? value : JSON.stringify(value);

            return `${key}: ${str}`;
        })
        .join('\n');
}

/**
 * Pull every markdown image out of a tool result so we can render them
 * inline. Smaller LLMs sometimes summarize the tool call instead of
 * echoing the markdown into their reply, so relying on the model to
 * surface images is fragile. This is also tool-agnostic: anything that
 * emits `![alt](url)` — GenerateImage today, future file-producing tools
 * tomorrow — gets previewed the same way.
 *
 * Accepts absolute `http(s)://…` URLs and relative paths (e.g.
 * `/uploads/abc.png`) so server-hosted files render without a rewrite.
 */
function extractMarkdownImages(
    result: string | undefined,
): Array<{ alt: string; url: string }> {
    if (!result) {
        return [];
    }

    const pattern = /!\[([^\]]*)\]\((\S+?)\)/g;
    const images: Array<{ alt: string; url: string }> = [];

    for (const match of result.matchAll(pattern)) {
        images.push({ alt: match[1], url: match[2] });
    }

    return images;
}

export function ToolCallBlock({ toolCall }: { toolCall: ToolCall }) {
    const [expanded, setExpanded] = useState(false);

    const { toolLabels } = useSharedProps();
    const label =
        toolLabels[toolCall.tool_name] ?? `Used ${toolCall.tool_name}`;
    const isPending = toolCall.result === undefined;
    const failed = toolCall.successful === false;
    const inlineImages = failed ? [] : extractMarkdownImages(toolCall.result);

    return (
        <div className="my-2">
            <button
                type="button"
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-surface-200 dark:hover:text-gray-300"
            >
                {isPending ? (
                    <LoadingSpinner />
                ) : (
                    <ChevronRightIcon
                        className={`size-3 transition-transform ${expanded ? 'rotate-90' : ''}`}
                    />
                )}
                <span>{isPending ? `${label}...` : label}</span>
                {failed && <span className="text-red-400">(failed)</span>}
            </button>

            {inlineImages.length > 0 && (
                <div className="mt-2 ml-2 flex flex-wrap gap-2">
                    {inlineImages.map((image, i) => (
                        <img
                            key={`${image.url}-${i}`}
                            src={image.url}
                            alt={image.alt}
                            className="max-w-md rounded-lg border border-gray-200 dark:border-surface-300"
                        />
                    ))}
                </div>
            )}

            {expanded && !isPending && (
                <div className="mt-1 ml-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-surface-300 dark:bg-surface-100">
                    {Object.keys(toolCall.arguments).length > 0 && (
                        <div className="mb-2">
                            <span className="font-medium text-gray-500">
                                Input
                            </span>
                            <pre className="mt-1 overflow-x-auto whitespace-pre-wrap text-gray-600 dark:text-gray-400">
                                {formatArguments(toolCall.arguments)}
                            </pre>
                        </div>
                    )}
                    <div>
                        <span className="font-medium text-gray-500">
                            Output
                        </span>
                        <pre
                            className={`mt-1 overflow-x-auto whitespace-pre-wrap ${failed ? 'text-red-400' : 'text-gray-600 dark:text-gray-400'}`}
                        >
                            {toolCall.error ?? toolCall.result}
                        </pre>
                    </div>
                </div>
            )}
        </div>
    );
}
