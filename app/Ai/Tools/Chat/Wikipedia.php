<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class Wikipedia implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 10;

    protected const USER_AGENT = 'GailBot/1.0 (https://github.com; local assistant)';

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
        private readonly ?int $maxResponseBytes = null,
    ) {}

    public function label(): string
    {
        return 'Looked it up on Wikipedia';
    }

    public function description(): Stringable|string
    {
        return 'Look up a topic on Wikipedia and return its title, short description, and intro paragraph. Prefer this over WebSearch for factual who/what/when questions — it returns clean, structured content rather than scraped search snippets. Supports any Wikipedia language via the "language" argument.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $language = strtolower(trim((string) ($request['language'] ?? 'en')));

        if ($query === '') {
            return 'Error: No query provided.';
        }

        if (! preg_match('/^[a-z]{2,10}$/', $language)) {
            return "Error: Invalid language code '{$language}'. Use an ISO 639 code like 'en' or 'fr'.";
        }

        $title = $this->resolveTitle($language, $query);

        if (is_string($title) && str_starts_with($title, 'Error:')) {
            return $title;
        }

        if ($title === null) {
            return "No Wikipedia article found for \"{$query}\".";
        }

        return $this->fetchSummary($language, $title, $query);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The topic, person, place, or thing to look up on Wikipedia.')
                ->required(),
            'language' => $schema->string()
                ->description("ISO 639 language code for the Wikipedia edition to query. Defaults to 'en'.")
                ->required()
                ->nullable(),
        ];
    }

    private function resolveTitle(string $language, string $query): ?string
    {
        $url = "https://{$language}.wikipedia.org/w/api.php";

        if ($blocked = $this->guard()->deniedHostFor($url)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->get($url, [
                    'action' => 'opensearch',
                    'search' => $query,
                    'limit' => 1,
                    'namespace' => 0,
                    'format' => 'json',
                ]);
        } catch (Throwable $e) {
            return "Error: Wikipedia search failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: Wikipedia search returned HTTP {$response->status()}.";
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data[1][0])) {
            return null;
        }

        $title = (string) $data[1][0];

        return $title === '' ? null : $title;
    }

    private function fetchSummary(string $language, string $title, string $originalQuery): string
    {
        $url = "https://{$language}.wikipedia.org/api/rest_v1/page/summary/".rawurlencode(str_replace(' ', '_', $title));

        if ($blocked = $this->guard()->deniedHostFor($url)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->get($url);
        } catch (Throwable $e) {
            return "Error: Wikipedia summary failed — {$e->getMessage()}";
        }

        if ($response->status() === 404) {
            return "No Wikipedia article found for \"{$originalQuery}\".";
        }

        if (! $response->successful()) {
            return "Error: Wikipedia summary returned HTTP {$response->status()}.";
        }

        $data = $response->json();

        if (! is_array($data)) {
            return 'Error: Wikipedia returned an unexpected response.';
        }

        return $this->format($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function format(array $data): string
    {
        $title = (string) ($data['title'] ?? '');
        $description = (string) ($data['description'] ?? '');
        $extract = (string) ($data['extract'] ?? '');
        $pageUrl = (string) ($data['content_urls']['desktop']['page'] ?? '');

        if ($title === '' && $extract === '') {
            return 'Error: Wikipedia returned no usable content.';
        }

        $lines = [];

        if ($title !== '') {
            $lines[] = $title;
        }

        if ($description !== '') {
            $lines[] = "({$description})";
        }

        if ($extract !== '') {
            $lines[] = '';
            $lines[] = $extract;
        }

        if ($pageUrl !== '') {
            $lines[] = '';
            $lines[] = "Source: {$pageUrl}";
        }

        $output = implode("\n", $lines);
        $maxBytes = $this->maxBytes();

        if (strlen($output) > $maxBytes) {
            $output = substr($output, 0, $maxBytes)."\n\n[Content truncated]";
        }

        return $output;
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('wikipedia');
    }

    private function maxBytes(): int
    {
        return $this->maxResponseBytes ?? (int) config('gail.tools.max_output_bytes.wikipedia', 15_000);
    }
}
