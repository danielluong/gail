<?php

namespace App\Ai\Tools\Research;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Fetches a URL and returns its main readable text. Strips <script> and
 * <style> nodes, then picks the largest text-bearing container (article,
 * main, body) via a "most characters wins" heuristic — light and
 * dependency-free, suitable for feeding into SummarizeTextTool or
 * ExtractFactsTool.
 *
 * Shares the HostGuard denylist with the chat-side WebFetch so metadata
 * endpoints and loopback addresses are refused even if the Researcher is
 * tricked into requesting them.
 */
class FetchPageTool implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 15;

    protected const MAX_BYTES = 8_000;

    protected const USER_AGENT = 'Mozilla/5.0 (compatible; GailResearchBot/1.0)';

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
    ) {}

    public function label(): string
    {
        return 'Fetched a page';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
        Fetch a web page and return its main readable content as plain
        text (scripts and styles removed, capped at ~8k characters). Use
        this after WebSearchTool to read a specific URL in depth.
        DESCRIPTION;
    }

    public function handle(Request $request): Stringable|string
    {
        $url = trim((string) ($request['url'] ?? ''));

        if ($url === '') {
            return 'Error: No URL provided.';
        }

        if ($blocked = $this->guard()->deniedHostFor($url)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        return $this->fetch($url);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Absolute http(s) URL to fetch.')
                ->required(),
        ];
    }

    /**
     * Fetch and clean a URL directly. Returns plain text, or a string
     * beginning with "Error:" on failure so the caller (agent or action)
     * can surface the problem without exception handling.
     */
    public function fetch(string $url): string
    {
        if ($blocked = $this->guard()->deniedHostFor($url)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5'])
                ->get($url);
        } catch (Throwable $e) {
            return "Error: Request failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: HTTP {$response->status()} from {$url}";
        }

        $contentType = (string) $response->header('Content-Type');
        $body = $response->body();

        if (str_contains($contentType, 'text/html')) {
            $body = $this->extractText($body);
        } elseif (str_contains($contentType, 'text/') || $contentType === '') {
            $body = trim($body);
        } else {
            return "Error: Unsupported content type '{$contentType}' from {$url}";
        }

        if (strlen($body) > self::MAX_BYTES) {
            $body = substr($body, 0, self::MAX_BYTES)."\n\n[Content truncated]";
        }

        return "URL: {$url}\n\n{$body}";
    }

    private function extractText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//script | //style | //noscript | //template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $candidates = ['//article', '//main', '//body'];
        $best = '';

        foreach ($candidates as $expr) {
            $nodes = $xpath->query($expr);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = $this->nodeText($node);

                if (strlen($text) > strlen($best)) {
                    $best = $text;
                }
            }

            if ($best !== '') {
                break;
            }
        }

        if ($best === '') {
            $best = $this->stripTags($html);
        }

        return $best;
    }

    private function nodeText(DOMNode $node): string
    {
        $text = html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function stripTags(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('web_fetch');
    }
}
