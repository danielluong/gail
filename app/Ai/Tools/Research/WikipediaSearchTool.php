<?php

namespace App\Ai\Tools\Research;

use App\Ai\Contracts\DisplayableTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * MediaWiki search for the Researcher pipeline. Returns a compact JSON
 * list of {title, url, snippet} hits that chains with FetchPageTool
 * the same way {@see WebSearchTool} does.
 *
 * Intentionally separate from the chat-side Wikipedia tool (which
 * returns a single article's summary) — this one is designed to hand
 * the Researcher a list of candidate sources to read in depth.
 *
 * Sweet spot: factual / historical / scientific / biographical
 * queries. For news, commerce, current events, or general web
 * results the Researcher should reach for WebSearchTool instead,
 * which is why the two tools are exposed side by side rather than
 * one falling back to the other behind the scenes.
 */
class WikipediaSearchTool implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 15;

    protected const DEFAULT_LIMIT = 5;

    protected const MAX_LIMIT = 10;

    protected const ENDPOINT = 'https://en.wikipedia.org/w/api.php';

    protected const USER_AGENT = 'GailResearchBot/1.0 (https://github.com; local assistant)';

    public function label(): string
    {
        return 'Searched Wikipedia';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
        Search Wikipedia via the MediaWiki API and return the top results
        as a JSON array of {title, url, snippet}. Use this for factual,
        historical, scientific, or biographical queries — anything you
        would expect an encyclopedia to cover.

        For news, commerce, current events, product reviews, or general
        web results, use WebSearchTool instead. Wikipedia will return
        poor or unrelated hits for those.
        DESCRIPTION;
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $limit = $this->normalizeLimit($request['limit'] ?? null);

        if ($query === '') {
            return json_encode(['error' => 'No query provided.']);
        }

        $results = $this->search($query, $limit);

        if (is_string($results)) {
            return json_encode(['error' => $results]);
        }

        return json_encode(['query' => $query, 'results' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query — works best for encyclopedic topics.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results. Defaults to 5, capped at 10.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * Direct-call API. Returns the results array on success, or a
     * short error string on failure so callers never have to
     * try/catch.
     *
     * @return list<array{title: string, url: string, snippet: string}>|string
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array|string
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->get(self::ENDPOINT, [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query,
                    'srlimit' => $limit,
                    'srprop' => 'snippet',
                    'format' => 'json',
                    'formatversion' => 2,
                ]);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('research.wikipedia_search.failed', [
                'error' => $e->getMessage(),
            ]);

            return "Wikipedia search failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Wikipedia search returned HTTP {$response->status()}.";
        }

        $data = $response->json();

        if (! is_array($data)) {
            return 'Wikipedia search unavailable — upstream returned unexpected response.';
        }

        $rows = data_get($data, 'query.search');

        if (! is_array($rows)) {
            return [];
        }

        $results = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title)),
                'snippet' => $this->stripHtml((string) ($row['snippet'] ?? '')),
            ];
        }

        return $results;
    }

    /**
     * Wikipedia search snippets come wrapped in
     * `<span class="searchmatch">` highlights; strip the markup and
     * collapse whitespace so they read as plain one-liners when the
     * Researcher forwards them to the Editor.
     */
    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeLimit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $value;

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
