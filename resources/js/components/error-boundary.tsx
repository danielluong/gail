import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';

type Props = {
    children: ReactNode;
    fallback?: ReactNode;
};

type State = {
    error: Error | null;
};

export class ErrorBoundary extends Component<Props, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error(
            'Gail unhandled render error:',
            error,
            info.componentStack,
        );
    }

    reset = () => this.setState({ error: null });

    render() {
        if (this.state.error === null) {
            return this.props.children;
        }

        if (this.props.fallback !== undefined) {
            return this.props.fallback;
        }

        return (
            <div className="flex h-screen items-center justify-center p-8 text-center">
                <div className="max-w-md space-y-4">
                    <h1 className="text-xl font-semibold">
                        Something went wrong.
                    </h1>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        Gail ran into an unexpected error. You can try again
                        without losing your saved conversations.
                    </p>
                    <button
                        type="button"
                        onClick={this.reset}
                        className="rounded-lg bg-neutral-900 px-4 py-2 text-sm text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                    >
                        Reload view
                    </button>
                </div>
            </div>
        );
    }
}
