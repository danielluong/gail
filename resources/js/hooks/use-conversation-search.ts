import { useEffect, useRef, useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { apiFetch } from '@/lib/api';
import type { Conversation } from '@/types/chat';

const DEBOUNCE_MS = 300;

/**
 * Debounced conversation search. Exposes the current query, the
 * matching results (null = no active search), and an "in flight"
 * flag for UI spinner state.
 */
export function useConversationSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Conversation[] | null>(null);
    const [searching, setSearching] = useState(false);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        const trimmed = query.trim();

        if (trimmed === '') {
            setResults(null);
            setSearching(false);

            return;
        }

        setSearching(true);

        timerRef.current = setTimeout(async () => {
            try {
                const response = await apiFetch(
                    ConversationController.search.url({
                        query: { q: trimmed },
                    }),
                    { headers: { Accept: 'application/json' } },
                );
                const data: Conversation[] = await response.json();

                setResults(data);
            } catch {
                setResults([]);
            } finally {
                setSearching(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        };
    }, [query]);

    function clear() {
        setQuery('');
        setResults(null);
    }

    return {
        query,
        setQuery,
        results,
        searching,
        clear,
        isActive: query.trim() !== '',
    };
}
