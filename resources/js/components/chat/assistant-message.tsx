import { memo, useMemo } from 'react';
import type { ComponentPropsWithoutRef } from 'react';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import {
    extractCitations,
    extractSourcesFromMarkdown,
    linkifyCitations,
} from '@/lib/citations';
import type { Message } from '@/types/chat';
import { CodeBlock } from './code-block';
import { PhaseStrip } from './phase-strip';
import { ThinkingIndicator } from './thinking-indicator';
import { ToolCallBlock } from './tool-call-block';

/*
 * File extensions the browser treats as downloads rather than renderable
 * pages. Without a `download` attribute, clicking these via
 * target=_blank opens a new tab that immediately closes when the file
 * downloads — a "flash" with no user feedback. With `download`, the
 * file downloads in place and the chat tab stays focused.
 */
const DOWNLOADABLE_EXTENSIONS = new Set([
    'csv',
    'tsv',
    'xlsx',
    'xls',
    'json',
    'zip',
    'pdf',
    'parquet',
]);

function isDownloadableHref(href: string | undefined): boolean {
    if (!href) {
        return false;
    }

    try {
        const url = new URL(href, window.location.origin);
        const extension = url.pathname.split('.').pop()?.toLowerCase();

        return extension !== undefined && DOWNLOADABLE_EXTENSIONS.has(extension);
    } catch {
        return false;
    }
}

/*
 * Every link in an assistant reply — citations, model-generated URLs,
 * anything — points at an external resource the user wants to read
 * without losing their place in the chat. Open in a new tab with
 * rel=noopener to prevent the target page from reaching window.opener.
 * For file-download URLs we use the `download` attribute instead so
 * the file saves in place without flashing a new tab.
 */
function ExternalLink(props: ComponentPropsWithoutRef<'a'>) {
    if (isDownloadableHref(props.href)) {
        return <a {...props} download />;
    }

    return <a {...props} target="_blank" rel="noopener noreferrer" />;
}

type Props = {
    message: Message;
    isStreaming: boolean;
};

function AssistantMessageImpl({ message, isStreaming }: Props) {
    const citedContent = useMemo(() => {
        if (!message.content) {
            return message.content;
        }

        /*
         * Merge the two citation sources so one rewrite pass handles
         * both the chat agent (cites WebSearch tool results) and the
         * research agent (cites its final Sources section). The
         * Editor-composed Sources block takes precedence because it's
         * what the user actually sees numbered in the answer.
         */
        const citations = new Map([
            ...extractCitations(message.toolCalls),
            ...extractSourcesFromMarkdown(message.content),
        ]);

        return linkifyCitations(message.content, citations);
    }, [message.content, message.toolCalls]);

    return (
        <div
            className={`rounded-2xl px-4 py-2.5 ${
                message.error
                    ? 'border border-red-300 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200'
                    : 'text-gray-800 dark:text-gray-200'
            }`}
        >
            {message.phases && message.phases.length > 0 && (
                <PhaseStrip phases={message.phases} />
            )}
            {message.toolCalls && message.toolCalls.length > 0 && (
                <div className="mb-1">
                    {message.toolCalls.map((tc) => (
                        <ToolCallBlock key={tc.tool_id} toolCall={tc} />
                    ))}
                </div>
            )}
            <div className="prose prose-sm max-w-none dark:prose-invert prose-headings:text-gray-900 dark:prose-headings:text-gray-100 prose-p:text-gray-800 dark:prose-p:text-gray-200 prose-a:text-blue-600 dark:prose-a:text-blue-400 prose-strong:text-gray-900 dark:prose-strong:text-gray-100 prose-code:text-gray-800 prose-code:before:content-none prose-code:after:content-none dark:prose-code:text-gray-200 prose-pre:bg-transparent prose-pre:p-0 dark:prose-pre:bg-transparent">
                {message.content ? (
                    <Markdown
                        remarkPlugins={[remarkGfm]}
                        components={{ code: CodeBlock, a: ExternalLink }}
                    >
                        {citedContent}
                    </Markdown>
                ) : isStreaming ? (
                    <ThinkingIndicator status={message.status} />
                ) : (
                    !message.error && (
                        <p className="text-sm text-gray-400 italic dark:text-gray-500">
                            No response.
                        </p>
                    )
                )}
            </div>
        </div>
    );
}

/**
 * Memoized to skip re-renders during streaming for any assistant message
 * whose contents haven't changed. The upstream reducer preserves object
 * identity for untouched messages, so shallow comparison is enough.
 */
export const AssistantMessage = memo(AssistantMessageImpl);
