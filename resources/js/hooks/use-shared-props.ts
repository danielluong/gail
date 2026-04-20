import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types/auth';

/*
 * Typed accessor for props shared by HandleInertiaRequests::share().
 *
 * Inertia's PageProps interface includes an open `[key: string]: unknown`
 * index signature, which intersects into usePage().props and widens every
 * destructured key to `unknown`. Centralising the one cast here keeps the
 * rest of the UI fully typed without littering `as` assertions.
 */
export interface SharedProps {
    name: string;
    auth: Auth;
    toolLabels: Record<string, string>;
    transcriptionEnabled: boolean;
    aiProvider: string;
}

export function useSharedProps(): SharedProps {
    return usePage().props as unknown as SharedProps;
}
