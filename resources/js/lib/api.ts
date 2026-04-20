function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export function apiFetch(
    url: string,
    options: RequestInit = {},
): Promise<Response> {
    return fetch(url, {
        ...options,
        headers: {
            'X-XSRF-TOKEN': getCsrfToken(),
            ...options.headers,
        },
    });
}

export function apiJson(
    url: string,
    method: string,
    body: Record<string, unknown>,
): Promise<Response> {
    return apiFetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}
