<?php

use App\Ai\Tools\Chat\WebFetch;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('returns error when no url is provided', function () {
    $result = (string) (new WebFetch)->handle(new Request([]));

    expect($result)->toContain('Error: No URL provided');
});

test('returns plain text for text responses', function () {
    Http::fake([
        'https://example.com/raw' => Http::response('hello there', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]),
    ]);

    $result = (string) (new WebFetch)->handle(new Request([
        'url' => 'https://example.com/raw',
    ]));

    expect($result)
        ->toContain('URL: https://example.com/raw')
        ->toContain('Status: 200')
        ->toContain('hello there');
});

test('extracts main content from html via readability', function () {
    $html = <<<'HTML'
        <!DOCTYPE html>
        <html>
            <head><title>Welcome to the Example Article</title></head>
            <body>
                <nav><a>Home</a><a>Blog</a></nav>
                <header>Site Header</header>
                <main>
                    <article>
                        <h1>Welcome to the Example Article</h1>
                        <p>The quick brown fox jumps over the lazy dog. The quick brown fox jumps over the lazy dog. The quick brown fox jumps over the lazy dog.</p>
                        <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
                        <p>Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
                        <p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.</p>
                    </article>
                </main>
                <footer>Site Footer</footer>
            </body>
        </html>
    HTML;

    Http::fake([
        'https://example.com/article' => Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]),
    ]);

    $result = (string) (new WebFetch)->handle(new Request([
        'url' => 'https://example.com/article',
    ]));

    expect($result)
        ->toContain('Welcome to the Example Article')
        ->toContain('quick brown fox')
        ->not->toContain('<p>')
        ->not->toContain('<h1>');
});

test('rejects denylisted hosts', function () {
    $guard = new HostGuard(['evil.example']);

    $result = (string) (new WebFetch($guard))->handle(new Request([
        'url' => 'https://evil.example/page',
    ]));

    expect($result)->toContain("Error: Requests to 'evil.example' are blocked");
});

test('returns error for non-2xx responses', function () {
    Http::fake([
        'https://example.com/missing' => Http::response('Not Found', 404, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $result = (string) (new WebFetch)->handle(new Request([
        'url' => 'https://example.com/missing',
    ]));

    expect($result)->toContain('Error: HTTP 404');
});

test('truncates responses that exceed the byte cap', function () {
    $body = str_repeat('a', 200);

    Http::fake([
        'https://example.com/big' => Http::response($body, 200, [
            'Content-Type' => 'text/plain',
        ]),
    ]);

    $result = (string) (new WebFetch(maxResponseBytes: 50))->handle(new Request([
        'url' => 'https://example.com/big',
    ]));

    expect($result)->toContain('[Content truncated]');
});

test('rejects unsupported content types', function () {
    Http::fake([
        'https://example.com/binary' => Http::response('binary junk', 200, [
            'Content-Type' => 'application/octet-stream',
        ]),
    ]);

    $result = (string) (new WebFetch)->handle(new Request([
        'url' => 'https://example.com/binary',
    ]));

    expect($result)->toContain("Error: Unsupported content type 'application/octet-stream'");
});
