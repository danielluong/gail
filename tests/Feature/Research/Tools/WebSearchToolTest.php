<?php

use App\Ai\Tools\Guards\HostGuard;
use App\Ai\Tools\Research\WebSearchTool;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    // Clear the cross-call DDG throttle so one test's request can't
    // stall the next test via a 1.2s sleep on the process-wide static.
    WebSearchTool::resetThrottle();
});

function fakeResearchDuckDuckGoHtml(array $results): string
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

test('returns a structured JSON array of results', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(fakeResearchDuckDuckGoHtml([
            [
                'title' => 'Solar Energy Basics',
                'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://example.com/solar'),
                'snippet' => 'Overview of photovoltaic systems.',
            ],
            [
                'title' => 'Nuclear Plant Lifecycle',
                'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://example.org/nuclear'),
                'snippet' => 'Reactor decommissioning costs.',
            ],
        ]), 200),
    ]);

    $payload = (string) (new WebSearchTool)->handle(new Request([
        'query' => 'solar vs nuclear',
    ]));

    $decoded = json_decode($payload, true);

    expect($decoded)
        ->toHaveKey('query', 'solar vs nuclear')
        ->toHaveKey('results');

    expect($decoded['results'])->toHaveCount(2);
    expect($decoded['results'][0])->toMatchArray([
        'title' => 'Solar Energy Basics',
        'url' => 'https://example.com/solar',
        'snippet' => 'Overview of photovoltaic systems.',
    ]);
});

test('respects the limit argument', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(fakeResearchDuckDuckGoHtml([
            ['title' => 'One', 'href' => 'https://a.test/', 'snippet' => 'a'],
            ['title' => 'Two', 'href' => 'https://b.test/', 'snippet' => 'b'],
            ['title' => 'Three', 'href' => 'https://c.test/', 'snippet' => 'c'],
        ]), 200),
    ]);

    $payload = (string) (new WebSearchTool)->handle(new Request([
        'query' => 'anything',
        'limit' => 2,
    ]));

    $decoded = json_decode($payload, true);

    expect($decoded['results'])->toHaveCount(2);
    expect(array_column($decoded['results'], 'title'))->toBe(['One', 'Two']);
});

test('filters denied hosts', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(fakeResearchDuckDuckGoHtml([
            [
                'title' => 'Good',
                'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://good.example/'),
                'snippet' => 'ok',
            ],
            [
                'title' => 'Bad',
                'href' => '//duckduckgo.com/l/?uddg='.urlencode('https://evil.example/'),
                'snippet' => 'nope',
            ],
        ]), 200),
    ]);

    $payload = (string) (new WebSearchTool(new HostGuard(['evil.example'])))
        ->handle(new Request(['query' => 'x']));

    $decoded = json_decode($payload, true);

    expect($decoded['results'])->toHaveCount(1);
    expect($decoded['results'][0]['title'])->toBe('Good');
});

test('reports error on blank query', function () {
    $payload = (string) (new WebSearchTool)->handle(new Request(['query' => '  ']));

    expect(json_decode($payload, true))
        ->toHaveKey('error');
});

test('reports error when duckduckgo rate-limits both attempts', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response('', 202),
    ]);

    $payload = (string) (new WebSearchTool(null, 0, 0, 0))->handle(new Request(['query' => 'x']));

    expect(json_decode($payload, true)['error'] ?? '')
        ->toContain('rate-limited two attempts')
        ->toContain('WikipediaSearchTool');
    Http::assertSentCount(2);
});

test('retries once when the first attempt is rate-limited', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::sequence()
            ->push('', 202)
            ->push(fakeResearchDuckDuckGoHtml([
                [
                    'title' => 'Recovered',
                    'href' => 'https://ok.test/',
                    'snippet' => 'second try worked',
                ],
            ]), 200),
    ]);

    $payload = (string) (new WebSearchTool(null, 0, 0, 0))->handle(new Request(['query' => 'x']));
    $decoded = json_decode($payload, true);

    expect($decoded['results'])->toHaveCount(1);
    expect($decoded['results'][0]['title'])->toBe('Recovered');
    Http::assertSentCount(2);
});

test('rotates the User-Agent across DDG attempts', function () {
    $ddgAgents = [];

    Http::fake(function ($request) use (&$ddgAgents) {
        $ddgAgents[] = $request->header('User-Agent')[0] ?? '';

        return Http::response('', 202);
    });

    (new WebSearchTool(null, 0, 0, 0))->handle(new Request(['query' => 'x']));

    expect($ddgAgents)->toHaveCount(2);

    foreach ($ddgAgents as $ua) {
        expect($ua)->toStartWith('Mozilla/5.0');
    }
});

test('direct search() method returns array of results', function () {
    Http::fake([
        'html.duckduckgo.com/*' => Http::response(fakeResearchDuckDuckGoHtml([
            ['title' => 'Only', 'href' => 'https://ok.test/', 'snippet' => 's'],
        ]), 200),
    ]);

    $results = (new WebSearchTool)->search('query', 5);

    expect($results)->toBeArray()->toHaveCount(1);
    expect($results[0])->toMatchArray([
        'title' => 'Only',
        'url' => 'https://ok.test/',
    ]);
});
