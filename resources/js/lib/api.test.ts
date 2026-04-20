import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { apiFetch, apiJson } from './api';

type FetchMock = ReturnType<typeof vi.fn>;

let fetchMock: FetchMock;

beforeEach(() => {
    document.cookie = '';
    fetchMock = vi.fn(() =>
        Promise.resolve(new Response('{}', { status: 200 })),
    );
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

describe('apiFetch', () => {
    it('sends the URL-decoded XSRF token from the cookie', async () => {
        document.cookie = 'XSRF-TOKEN=raw%2Btoken';

        await apiFetch('/foo');

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const headers = init.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('raw+token');
    });

    it('sends an empty XSRF header when the cookie is absent', async () => {
        await apiFetch('/foo');

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const headers = init.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('');
    });

    it('merges caller-supplied headers with the XSRF header', async () => {
        document.cookie = 'XSRF-TOKEN=abc';

        await apiFetch('/foo', {
            headers: { Accept: 'application/json' },
        });

        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const headers = init.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('abc');
        expect(headers['Accept']).toBe('application/json');
    });

    it('forwards the method and body unchanged', async () => {
        await apiFetch('/foo', { method: 'POST', body: 'hi' });

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/foo');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('hi');
    });
});

describe('apiJson', () => {
    it('serializes the body as JSON and sets Content-Type', async () => {
        document.cookie = 'XSRF-TOKEN=tkn';

        await apiJson('/api/things', 'POST', { name: 'Gail' });

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const headers = init.headers as Record<string, string>;

        expect(url).toBe('/api/things');
        expect(init.method).toBe('POST');
        expect(init.body).toBe('{"name":"Gail"}');
        expect(headers['Content-Type']).toBe('application/json');
        expect(headers['X-XSRF-TOKEN']).toBe('tkn');
    });

    it('supports PATCH and DELETE methods', async () => {
        await apiJson('/api/things/1', 'PATCH', { title: 't' });
        await apiJson('/api/things/1', 'DELETE', {});

        expect(
            (fetchMock.mock.calls[0] as [string, RequestInit])[1].method,
        ).toBe('PATCH');
        expect(
            (fetchMock.mock.calls[1] as [string, RequestInit])[1].method,
        ).toBe('DELETE');
    });
});
