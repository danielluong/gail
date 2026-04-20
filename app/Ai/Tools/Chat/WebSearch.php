<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class WebSearch implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 15;

    protected const DEFAULT_LIMIT = 5;

    protected const MAX_LIMIT = 10;

    protected const ENDPOINT = 'https://html.duckduckgo.com/html/';

    protected const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
        private readonly ?int $maxResponseBytes = null,
    ) {}

    public function label(): string
    {
        return 'Searched the web';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
Search the web and return a ranked list of results with titles, URLs, and
snippets. Use this to find current information, news, documentation, or
any topic that requires fresh web results. For reading a specific URL you
already know, use WebFetch.

When you reference facts that came from a search result in your reply,
cite the result with bracket notation like [1] or [2] matching the
numbers in the returned list. Do not invent citation numbers.
DESCRIPTION;
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $limit = $this->normalizeLimit($request['limit'] ?? null);

        if ($query === '') {
            return 'Error: No query provided.';
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->asForm()
                ->post(self::ENDPOINT, ['q' => $query]);
        } catch (Throwable $e) {
            return "Error: Search failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: Search returned HTTP {$response->status()}.";
        }

        if ($response->status() === 202) {
            return 'Error: Search temporarily unavailable — please try again shortly.';
        }

        try {
            $results = $this->parseResults($response->body(), $limit);
        } catch (Throwable $e) {
            report($e);

            return 'Error: Search unavailable — upstream result format could not be parsed.';
        }

        if ($results === []) {
            return "No results found for \"{$query}\".";
        }

        return $this->format($query, $results);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return. Defaults to 5, capped at 10.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function parseResults(string $html, int $limit): array
    {
        if ($html === '') {
            return [];
        }

        $doc = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result ')]");

        if ($nodes === false) {
            return [];
        }

        $guard = $this->guard();
        $results = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $titleLink = $this->firstByClass($xpath, $node, 'result__a');

            if (! $titleLink instanceof DOMElement) {
                continue;
            }

            $url = $this->resolveUrl($titleLink->getAttribute('href'));

            if ($url === null) {
                continue;
            }

            if ($guard->deniedHostFor($url) !== null) {
                continue;
            }

            $title = $this->collapseWhitespace($titleLink->textContent);
            $snippetNode = $this->firstByClass($xpath, $node, 'result__snippet');
            $snippet = $snippetNode instanceof DOMElement
                ? $this->collapseWhitespace($snippetNode->textContent)
                : '';

            if ($title === '') {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function firstByClass(DOMXPath $xpath, DOMElement $context, string $class): ?DOMElement
    {
        $query = ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
        $result = $xpath->query($query, $context);

        if ($result === false || $result->length === 0) {
            return null;
        }

        $first = $result->item(0);

        return $first instanceof DOMElement ? $first : null;
    }

    private function resolveUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        // DuckDuckGo wraps real URLs in /l/?uddg=<encoded>&rut=...
        if (str_contains($href, 'uddg=')) {
            $query = parse_url($href, PHP_URL_QUERY);

            if (is_string($query)) {
                parse_str($query, $params);

                if (isset($params['uddg']) && is_string($params['uddg'])) {
                    $href = urldecode($params['uddg']);
                }
            }
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        if (! preg_match('#^https?://#i', $href)) {
            return null;
        }

        return $href;
    }

    private function collapseWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * @param  list<array{title: string, url: string, snippet: string}>  $results
     */
    private function format(string $query, array $results): string
    {
        $lines = ["Search results for \"{$query}\":", ''];

        foreach ($results as $index => $result) {
            $n = $index + 1;
            $lines[] = "{$n}. {$result['title']}";
            $lines[] = "   {$result['url']}";

            if ($result['snippet'] !== '') {
                $lines[] = "   {$result['snippet']}";
            }

            $lines[] = '';
        }

        $output = rtrim(implode("\n", $lines));
        $maxBytes = $this->maxBytes();

        if (strlen($output) > $maxBytes) {
            $output = substr($output, 0, $maxBytes)."\n\n[Results truncated]";
        }

        /*
         * Surface the citation directive right next to the results. The
         * base system prompt carries the same rule, but small models
         * often drop instructions from the preamble by the time they
         * compose; repeating it immediately after the tool output is the
         * placement that most reliably survives.
         */
        return $output."\n\nCite these results in your reply using bracket notation like [1] or [2, 3] that matches the numbers above.";
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

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('web_search');
    }

    private function maxBytes(): int
    {
        return $this->maxResponseBytes ?? (int) config('gail.tools.max_output_bytes.web_search', 10_000);
    }
}
