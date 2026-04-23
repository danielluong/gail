<?php

namespace App\Ai\Tools\Research;

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

/**
 * DuckDuckGo HTML search tailored for the Researcher pipeline. Returns
 * a compact JSON array of `{title, url, snippet}` so downstream agents
 * can iterate over structured results and chain into {@see FetchPageTool}.
 *
 * Paired with {@see WikipediaSearchTool} — same return shape, different
 * source. DDG covers the general web (news, commerce, current events);
 * Wikipedia covers encyclopedic queries. The Researcher is told via
 * both tools' descriptions to pick the right one per sub-topic rather
 * than having one silently fall back to the other (Wikipedia hits
 * returned for a restaurant query are worse than an explicit error).
 *
 * The project also ships app/Ai/Tools/Chat/WebSearch.php which formats
 * results as citation-friendly text for the chat agent. This class is
 * intentionally self-contained so the Research module can be lifted
 * out or audited on its own.
 */
class WebSearchTool implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 15;

    protected const DEFAULT_LIMIT = 5;

    protected const MAX_LIMIT = 10;

    protected const ENDPOINT = 'https://html.duckduckgo.com/html/';

    /**
     * DuckDuckGo's HTML endpoint aggressively 202s bursty traffic that
     * looks bot-like. Rotating the User-Agent across a small pool
     * spreads the per-UA/per-fingerprint rate-limit counter so a
     * Researcher that fires several searches in a row is less likely
     * to burn its quota on the first UA alone.
     *
     * @var list<string>
     */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
    ];

    /**
     * Minimum wall-clock gap (microseconds) between two DDG calls in
     * the same PHP request. The Researcher fires several searches
     * back-to-back per turn, which used to trip DDG's IP-based rate
     * limiter on the second or third call; enforcing a 1.2s floor
     * keeps the burst below their threshold while adding imperceptibly
     * little latency to the overall research turn (the calls were
     * going to cost that much in retry jitter anyway).
     *
     * Shared via static state because the throttle is a property of
     * the current PHP process, not of any single tool instance — the
     * container resolves the tool fresh per tool call inside the
     * laravel/ai loop.
     */
    private const DEFAULT_MIN_GAP_MICROS = 1_200_000;

    private static float $lastDdgCallAt = 0.0;

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
        /*
         * Sleep window (microseconds) between a 202 and the retry.
         * Exposed so tests can pass 0/0 and keep the suite fast; the
         * defaults give DDG's rate limiter time to decrement without
         * stalling a real user-facing request.
         */
        private readonly int $retrySleepMinMicros = 500_000,
        private readonly int $retrySleepMaxMicros = 1_500_000,
        /*
         * Cross-call throttle (microseconds). Tests pass 0 to
         * skip the gap; production uses the default constant.
         */
        private readonly int $minDdgGapMicros = self::DEFAULT_MIN_GAP_MICROS,
    ) {}

    public function label(): string
    {
        return 'Searched the web';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
        Search the web via DuckDuckGo and return the top results as a
        JSON array of {title, url, snippet}. Use this for news, current
        events, commerce, product reviews, restaurants, local info, and
        any other general-web topic.

        For factual, historical, scientific, or biographical queries
        prefer WikipediaSearchTool — it returns cleaner sources for
        those topics. If DDG rate-limits here and the query is
        encyclopedic, switch to WikipediaSearchTool and continue.
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

        if ($results === []) {
            return json_encode(['query' => $query, 'results' => []]);
        }

        return json_encode(['query' => $query, 'results' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results. Defaults to 5, capped at 10.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * Run the search directly. Exposed for Action/test code that needs a
     * straight PHP call without going through the Tool contract.
     *
     * @return list<array{title: string, url: string, snippet: string}>|string
     *                                                                         List of results on success, or a string error message.
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array|string
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        /*
         * Up to two attempts: if DDG 202s (rate-limit heuristic) we
         * jitter, rotate the UA, and retry once. Hard failures (network
         * exception, non-202 error status, parse failure) short-circuit
         * the retry because they don't get better by trying again.
         */
        $first = $this->searchOnce($query, $limit);

        if (! $this->isRateLimited($first)) {
            return $first;
        }

        $this->sleepBetweenAttempts();

        $second = $this->searchOnce($query, $limit);

        if (! $this->isRateLimited($second)) {
            return $second;
        }

        return 'Search temporarily unavailable — DuckDuckGo rate-limited two attempts in a row. Try again in a minute, or switch to WikipediaSearchTool if the query is encyclopedic.';
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>|string
     */
    private function searchOnce(string $query, int $limit): array|string
    {
        $this->throttleDdg();

        /*
         * GET + the full browser-ish header set is what a real user
         * clicking through to https://html.duckduckgo.com/html/?q=X
         * sends. Earlier iterations POSTed form data, which trips
         * DDG's bot heuristic far more aggressively.
         */
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent($this->pickUserAgent())
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'DNT' => '1',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                ])
                ->get(self::ENDPOINT, ['q' => $query]);
        } catch (Throwable $e) {
            return "Search failed — {$e->getMessage()}";
        }

        if ($response->status() === 202) {
            return 'Search temporarily unavailable — please try again shortly.';
        }

        if (! $response->successful()) {
            return "Search returned HTTP {$response->status()}.";
        }

        try {
            return $this->parseResults($response->body(), $limit);
        } catch (Throwable $e) {
            report($e);

            return 'Search unavailable — upstream result format could not be parsed.';
        }
    }

    /**
     * Block the current call until at least $minDdgGapMicros have
     * passed since the last DDG hit in this PHP process. First call
     * in a request is free; subsequent calls wait. Acts as the
     * primary defence against burst-triggered 202s.
     */
    private function throttleDdg(): void
    {
        if ($this->minDdgGapMicros <= 0) {
            self::$lastDdgCallAt = microtime(true);

            return;
        }

        $gapSeconds = $this->minDdgGapMicros / 1_000_000;
        $elapsed = microtime(true) - self::$lastDdgCallAt;

        if ($elapsed < $gapSeconds) {
            usleep((int) (($gapSeconds - $elapsed) * 1_000_000));
        }

        self::$lastDdgCallAt = microtime(true);
    }

    private function isRateLimited(array|string $result): bool
    {
        return is_string($result) && str_contains($result, 'please try again shortly');
    }

    private function sleepBetweenAttempts(): void
    {
        $min = max(0, $this->retrySleepMinMicros);
        $max = max($min, $this->retrySleepMaxMicros);

        if ($max === 0) {
            return;
        }

        usleep(random_int($min, $max));
    }

    private function pickUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }

    /**
     * Reset the cross-call DDG throttle. Intended for test setup —
     * production code never needs to call this because the static
     * naturally starts at 0 in a fresh PHP process and the first
     * DDG call of a request is always free.
     */
    public static function resetThrottle(): void
    {
        self::$lastDdgCallAt = 0.0;
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

            if ($url === null || $guard->deniedHostFor($url) !== null) {
                continue;
            }

            $title = $this->collapseWhitespace($titleLink->textContent);

            if ($title === '') {
                continue;
            }

            $snippetNode = $this->firstByClass($xpath, $node, 'result__snippet');
            $snippet = $snippetNode instanceof DOMElement
                ? $this->collapseWhitespace($snippetNode->textContent)
                : '';

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

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('web_search');
    }
}
