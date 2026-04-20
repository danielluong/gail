<?php

use App\Ai\Tools\Chat\WebSearch;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function fakeDuckDuckGoHtml(array $results): string
{
    $cards = array_map(function (array $result): string {
        $title = htmlspecialchars($result['title'], ENT_QUOTES);
        $href = htmlspecialchars($result['href'], ENT_QUOTES);
        $snippet = htmlspecialchars($result['snippet'], ENT_QUOTES);

        return <<<HTML
            <div class="result results_links">
                <h2 class="result__title">
                    <a class="result__a" href="{$href}">{$title}</a>
                </h2>
                <a class="result__snippet" href="{$href}">{$snippet}</a>
            </div>
        HTML;
    }, $results);

    return '<html><body>'.implode("\n", $cards).'</body></html>';
}

test('returns error when query is blank', function () {
    $result = (string) (new WebSearch)->handle(new Request(['query' => '  ']));

    expect($result)->toContain('Error: No query provided');
});

test('parses duckduckgo html into a ranked list', function () {
    $html = fakeDuckDuckGoHtml([
        [
            'title' => 'First Result',
            'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://example.com/first').'&rut=abc',
            'snippet' => 'Snippet about the first result.',
        ],
        [
            'title' => 'Second Result',
            'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://example.org/second').'&rut=def',
            'snippet' => 'Snippet about the second result.',
        ],
    ]);

    Http::fake([
        'html.duckduckgo.com/*' => Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]),
    ]);

    $result = (string) (new WebSearch)->handle(new Request([
        'query' => 'laravel testing',
    ]));

    expect($result)
        ->toContain('Search results for "laravel testing"')
        ->toContain('1. First Result')
        ->toContain('https://example.com/first')
        ->toContain('Snippet about the first result.')
        ->toContain('2. Second Result')
        ->toContain('https://example.org/second');
});

test('filters results against the host denylist', function () {
    $html = fakeDuckDuckGoHtml([
        [
            'title' => 'Good Result',
            'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://good.example/page'),
            'snippet' => 'safe',
        ],
        [
            'title' => 'Bad Result',
            'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://evil.example/page'),
            'snippet' => 'nope',
        ],
    ]);

    Http::fake([
        'html.duckduckgo.com/*' => Http::response($html, 200),
    ]);

    $guard = new HostGuard(['evil.example']);

    $result = (string) (new WebSearch($guard))->handle(new Request([
        'query' => 'anything',
    ]));

    expect($result)
        ->toContain('Good Result')
        ->not->toContain('Bad Result')
        ->not->toContain('evil.example');
});

test('respects the limit argument', function () {
    $html = fakeDuckDuckGoHtml([
        ['title' => 'One', 'href' => 'https://a.test/', 'snippet' => 'a'],
        ['title' => 'Two', 'href' => 'https://b.test/', 'snippet' => 'b'],
        ['title' => 'Three', 'href' => 'https://c.test/', 'snippet' => 'c'],
    ]);

    Http::fake([
        'html.duckduckgo.com/*' => Http::response($html, 200),
    ]);

    $result = (string) (new WebSearch)->handle(new Request([
        'query' => 'anything',
        'limit' => 2,
    ]));

    expect($result)
        ->toContain('1. One')
        ->toContain('2. Two')
        ->not->toContain('3. Three');
});

test('reports empty search results cleanly', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response('<html><body></body></html>', 200),
    ]);

    $result = (string) (new WebSearch)->handle(new Request([
        'query' => 'xyzzy nothing matches',
    ]));

    expect($result)->toContain('No results found for "xyzzy nothing matches"');
});

test('returns error when duckduckgo rate-limits the request', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response('<html><body></body></html>', 202),
    ]);

    $result = (string) (new WebSearch)->handle(new Request([
        'query' => 'anything',
    ]));

    expect($result)->toContain('Search temporarily unavailable');
});

test('returns error when duckduckgo returns a non-success status', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response('oops', 503),
    ]);

    $result = (string) (new WebSearch)->handle(new Request([
        'query' => 'anything',
    ]));

    expect($result)->toContain('Error: Search returned HTTP 503');
});
