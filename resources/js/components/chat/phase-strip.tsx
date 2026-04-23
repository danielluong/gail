import type { ReactNode } from 'react';
import { LoadingSpinner } from '@/components/icons';
import type { Phase } from '@/types/chat';

/*
 * Live strip of agent-phase chips rendered inside the assistant
 * bubble for multi-agent workflows (currently only the Research
 * pipeline). During a stream each chip flips from "running" to
 * "complete" as the backend yields `phase` SSE frames; the persisted
 * copy rides on ConversationMessage.meta.phases so a page refresh
 * shows the same sequence as a static record.
 *
 * Deliberately tiny and muted so it reads as an inline caption, not
 * as a first-class UI element competing with the answer text. The
 * Critic's approval verdict surfaces as an icon (⚠️ when not
 * approved) plus a hover tooltip with confidence + issue summary.
 */
export function PhaseStrip({ phases }: { phases: Phase[] }) {
    if (phases.length === 0) {
        return null;
    }

    return (
        <div className="mb-2 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            {phases.map((phase, index) => (
                <div key={phase.key} className="flex items-center gap-1.5">
                    {index > 0 && (
                        <span
                            aria-hidden="true"
                            className="text-gray-300 dark:text-gray-600"
                        >
                            →
                        </span>
                    )}
                    <PhaseChip phase={phase} />
                </div>
            ))}
        </div>
    );
}

function PhaseChip({ phase }: { phase: Phase }) {
    const { icon, tone } = chipAppearance(phase);
    const tooltip = chipTooltip(phase);

    return (
        <span
            title={tooltip}
            className={`inline-flex items-center gap-1 rounded-lg px-2 py-0.5 transition-colors ${tone}`}
        >
            {icon}
            <span>{phase.status === 'running' ? `${phase.label}…` : phase.label}</span>
        </span>
    );
}

function chipAppearance(phase: Phase): { icon: ReactNode; tone: string } {
    if (phase.status === 'running') {
        return {
            icon: <LoadingSpinner />,
            tone: 'bg-gray-100 dark:bg-surface-200',
        };
    }

    if (phase.status === 'failed') {
        return {
            icon: (
                <span aria-hidden="true" className="text-red-500">
                    ✕
                </span>
            ),
            tone: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
        };
    }

    // Complete — the Critic's approval verdict (if present) swaps the
    // neutral check for a warning sign when something was flagged.
    if (phase.approved === false) {
        return {
            icon: (
                <span aria-hidden="true" className="text-amber-500">
                    ⚠
                </span>
            ),
            tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        };
    }

    return {
        icon: (
            <span aria-hidden="true" className="text-emerald-500">
                ✓
            </span>
        ),
        tone: 'bg-gray-100 dark:bg-surface-200',
    };
}

function chipTooltip(phase: Phase): string | undefined {
    if (phase.approved === undefined && phase.confidence === undefined) {
        return undefined;
    }

    if (phase.approved === false) {
        const issues = phase.issues ?? [];
        const head = issues.length > 0
            ? `Flagged — ${issues.length} issue${issues.length === 1 ? '' : 's'}`
            : 'Flagged';
        const tail = issues.length > 0 ? `: ${issues.slice(0, 3).join('; ')}` : '';
        const confidence = phase.confidence ? ` (${phase.confidence} confidence)` : '';

        return `${head}${tail}${confidence}`;
    }

    const confidence = phase.confidence ?? 'unknown';

    return `Reviewed — ${confidence} confidence`;
}
