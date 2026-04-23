<?php

use App\Ai\Tools\Guards\HostGuard;
use App\Ai\Tools\Research\FetchPageTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('returns error when no url is provided', function () {
    $result = (string) (new FetchPageTool)->handle(new Request([]));

    expect($result)->toContain('Error: No URL provided');
});

test('extracts main content and strips scripts and styles', function () {
    $html = <<<'HTML'
        <html>
            <head>
                <style>.x { color: red; }</style>
            </head>
            <body>
                <script>alert('hi')</script>
                <article>
                    <h1>Main Topic</h1>
                    <p>The quick brown fox jumps over the lazy dog.</p>
                    <p>Lorem ipsum dolor sit amet.</p>
                </article>
                <footer>site footer</footer>
            </body>
        </html>
    HTML;

    Http::fake([
        'https://example.com/article' => Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]),
    ]);

    $result = (string) (new FetchPageTool)->handle(new Request([
        'url' => 'https://example.com/article',
    ]));

    expect($result)
        ->toContain('Main Topic')
        ->toContain('quick brown fox')
        ->not->toContain('alert(')
        ->not->toContain('color: red');
});

test('blocks denylisted hosts', function () {
    $result = (string) (new FetchPageTool(new HostGuard(['evil.example'])))
        ->handle(new Request(['url' => 'https://evil.example/']));

    expect($result)->toContain("Error: Requests to 'evil.example' are blocked");
});

test('returns error on non-2xx', function () {
    Http::fake([
        'https://example.com/missing' => Http::response('Not Found', 404, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $result = (string) (new FetchPageTool)->handle(new Request([
        'url' => 'https://example.com/missing',
    ]));

    expect($result)->toContain('Error: HTTP 404');
});

test('returns plain text for plain text responses', function () {
    Http::fake([
        'https://example.com/raw' => Http::response('just plain text', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]),
    ]);

    $result = (string) (new FetchPageTool)->handle(new Request([
        'url' => 'https://example.com/raw',
    ]));

    expect($result)->toContain('just plain text');
});

test('direct fetch() method returns cleaned text', function () {
    Http::fake([
        'https://example.com/page' => Http::response(
            '<html><body><main><p>content here</p></main></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $text = (new FetchPageTool)->fetch('https://example.com/page');

    expect($text)->toContain('content here')->not->toContain('<p>');
});
