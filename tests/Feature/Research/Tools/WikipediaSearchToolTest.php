<?php

use App\Ai\Tools\Research\WikipediaSearchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('parses the MediaWiki search response into structured results', function () {
    Http::fake([
        'en.wikipedia.org/*' => Http::response([
            'query' => [
                'search' => [
                    [
                        'title' => 'Solar power',
                        'snippet' => '<span class="searchmatch">Solar</span> power converts sunlight.',
                    ],
                    [
                        'title' => 'Solar cell',
                        'snippet' => 'A <span class="searchmatch">solar</span> cell turns photons into current.',
                    ],
                ],
            ],
        ], 200),
    ]);

    $payload = (string) (new WikipediaSearchTool)->handle(new Request([
        'query' => 'solar power',
    ]));

    $decoded = json_decode($payload, true);

    expect($decoded)
        ->toHaveKey('query', 'solar power')
        ->toHaveKey('results');

    expect($decoded['results'])->toHaveCount(2);
    expect($decoded['results'][0])->toMatchArray([
        'title' => 'Solar power',
        'url' => 'https://en.wikipedia.org/wiki/Solar_power',
    ]);
    expect($decoded['results'][0]['snippet'])->toBe('Solar power converts sunlight.');
});

test('URL-encodes spaces in article titles', function () {
    Http::fake([
        'en.wikipedia.org/*' => Http::response([
            'query' => [
                'search' => [[
                    'title' => 'Nuclear power plant',
                    'snippet' => 'nuclear reactor site',
                ]],
            ],
        ], 200),
    ]);

    $payload = (string) (new WikipediaSearchTool)->handle(new Request([
        'query' => 'nuclear plants',
    ]));

    $decoded = json_decode($payload, true);

    expect($decoded['results'][0]['url'])
        ->toBe('https://en.wikipedia.org/wiki/Nuclear_power_plant');
});

test('returns empty results when MediaWiki has no hits', function () {
    Http::fake([
        'en.wikipedia.org/*' => Http::response([
            'query' => ['search' => []],
        ], 200),
    ]);

    $payload = (string) (new WikipediaSearchTool)->handle(new Request([
        'query' => 'xyzzynothingmatches',
    ]));

    $decoded = json_decode($payload, true);

    expect($decoded['results'])->toBe([]);
});

test('reports error on a non-2xx MediaWiki response', function () {
    Http::fake([
        'en.wikipedia.org/*' => Http::response('', 503),
    ]);

    $payload = (string) (new WikipediaSearchTool)->handle(new Request(['query' => 'x']));

    expect(json_decode($payload, true)['error'] ?? '')
        ->toContain('HTTP 503');
});

test('reports error on blank query', function () {
    $payload = (string) (new WikipediaSearchTool)->handle(new Request(['query' => '  ']));

    expect(json_decode($payload, true))->toHaveKey('error');
});

test('respects the limit argument in the request payload', function () {
    $capturedLimit = null;

    Http::fake(function ($request) use (&$capturedLimit) {
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $params);
        $capturedLimit = $params['srlimit'] ?? null;

        return Http::response(['query' => ['search' => []]], 200);
    });

    (new WikipediaSearchTool)->handle(new Request([
        'query' => 'x',
        'limit' => 3,
    ]));

    expect($capturedLimit)->toBe('3');
});

test('direct search() method returns an array of results', function () {
    Http::fake([
        'en.wikipedia.org/*' => Http::response([
            'query' => [
                'search' => [[
                    'title' => 'Photovoltaics',
                    'snippet' => 'PV converts light to electricity.',
                ]],
            ],
        ], 200),
    ]);

    $results = (new WikipediaSearchTool)->search('photovoltaics', 5);

    expect($results)->toBeArray()->toHaveCount(1);
    expect($results[0]['url'])->toBe('https://en.wikipedia.org/wiki/Photovoltaics');
});
