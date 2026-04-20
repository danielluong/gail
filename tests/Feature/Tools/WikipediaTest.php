<?php

use App\Ai\Tools\Chat\Wikipedia;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('returns error when no query is provided', function () {
    $result = (string) (new Wikipedia)->handle(new Request([]));

    expect($result)->toContain('Error: No query provided');
});

test('rejects invalid language codes', function () {
    $result = (string) (new Wikipedia)->handle(new Request([
        'query' => 'Laravel',
        'language' => 'not-a-lang',
    ]));

    expect($result)->toContain("Error: Invalid language code 'not-a-lang'");
});

test('returns formatted summary for a matching article', function () {
    Http::fake([
        'en.wikipedia.org/w/api.php*' => Http::response([
            'Laravel',
            ['Laravel'],
            ['PHP web framework'],
            ['https://en.wikipedia.org/wiki/Laravel'],
        ], 200),
        'en.wikipedia.org/api/rest_v1/page/summary/Laravel' => Http::response([
            'title' => 'Laravel',
            'description' => 'Free, open-source PHP web framework',
            'extract' => 'Laravel is a free and open-source PHP web framework, created by Taylor Otwell and intended for the development of web applications following the model–view–controller architectural pattern.',
            'content_urls' => [
                'desktop' => ['page' => 'https://en.wikipedia.org/wiki/Laravel'],
            ],
        ], 200),
    ]);

    $result = (string) (new Wikipedia)->handle(new Request([
        'query' => 'Laravel framework',
    ]));

    expect($result)
        ->toContain('Laravel')
        ->toContain('(Free, open-source PHP web framework)')
        ->toContain('Laravel is a free and open-source PHP web framework')
        ->toContain('Source: https://en.wikipedia.org/wiki/Laravel');
});

test('reports when no article is found', function () {
    Http::fake([
        'en.wikipedia.org/w/api.php*' => Http::response(['xyzzy', [], [], []], 200),
    ]);

    $result = (string) (new Wikipedia)->handle(new Request([
        'query' => 'xyzzy nothing matches',
    ]));

    expect($result)->toContain('No Wikipedia article found for "xyzzy nothing matches"');
});

test('handles a 404 from the summary endpoint', function () {
    Http::fake([
        'en.wikipedia.org/w/api.php*' => Http::response([
            'ghost',
            ['Ghost Article'],
            [''],
            ['https://en.wikipedia.org/wiki/Ghost_Article'],
        ], 200),
        'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response('Not found', 404),
    ]);

    $result = (string) (new Wikipedia)->handle(new Request([
        'query' => 'ghost',
    ]));

    expect($result)->toContain('No Wikipedia article found for "ghost"');
});

test('supports non-english wikipedia editions', function () {
    Http::fake([
        'fr.wikipedia.org/w/api.php*' => Http::response([
            'Paris',
            ['Paris'],
            ['Capitale de la France'],
            ['https://fr.wikipedia.org/wiki/Paris'],
        ], 200),
        'fr.wikipedia.org/api/rest_v1/page/summary/Paris' => Http::response([
            'title' => 'Paris',
            'description' => 'Capitale de la France',
            'extract' => 'Paris est la capitale de la France.',
            'content_urls' => [
                'desktop' => ['page' => 'https://fr.wikipedia.org/wiki/Paris'],
            ],
        ], 200),
    ]);

    $result = (string) (new Wikipedia)->handle(new Request([
        'query' => 'Paris',
        'language' => 'fr',
    ]));

    expect($result)
        ->toContain('Paris')
        ->toContain('Capitale de la France')
        ->toContain('Source: https://fr.wikipedia.org/wiki/Paris');
});

test('respects the host denylist', function () {
    $guard = new HostGuard(['en.wikipedia.org']);

    $result = (string) (new Wikipedia($guard))->handle(new Request([
        'query' => 'Laravel',
    ]));

    expect($result)->toContain("Error: Requests to 'en.wikipedia.org' are blocked");
});

test('truncates excessively long content', function () {
    $longExtract = str_repeat('a', 200);

    Http::fake([
        'en.wikipedia.org/w/api.php*' => Http::response([
            'big',
            ['Big Article'],
            [''],
            ['https://en.wikipedia.org/wiki/Big_Article'],
        ], 200),
        'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
            'title' => 'Big Article',
            'extract' => $longExtract,
            'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Big_Article']],
        ], 200),
    ]);

    $result = (string) (new Wikipedia(maxResponseBytes: 50))->handle(new Request([
        'query' => 'big',
    ]));

    expect($result)->toContain('[Content truncated]');
});
