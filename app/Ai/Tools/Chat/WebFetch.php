<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use fivefilters\Readability\Configuration as ReadabilityConfiguration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class WebFetch implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 15;

    protected const USER_AGENT = 'Mozilla/5.0 (compatible; GailBot/1.0; +https://github.com)';

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
        private readonly ?int $maxResponseBytes = null,
    ) {}

    public function label(): string
    {
        return 'Read a web page';
    }

    public function description(): Stringable|string
    {
        return 'Fetch a web page and return its main readable content as plain text. Use this to read articles, documentation pages, or any single URL. For search, use WebSearch instead.';
    }

    public function handle(Request $request): Stringable|string
    {
        $url = trim($request['url'] ?? '');

        if ($url === '') {
            return 'Error: No URL provided.';
        }

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

        $status = $response->status();
        $contentType = (string) $response->header('Content-Type');
        $body = $response->body();

        if (! $response->successful()) {
            return "Error: HTTP {$status} from {$url}";
        }

        if (str_contains($contentType, 'text/html')) {
            $body = $this->extractReadable($body, $url);
        } elseif (str_contains($contentType, 'text/') || $contentType === '') {
            $body = trim($body);
        } else {
            return "Error: Unsupported content type '{$contentType}' from {$url}";
        }

        $maxBytes = $this->maxBytes();

        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes)."\n\n[Content truncated]";
        }

        return "URL: {$url}\nStatus: {$status}\n\n{$body}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('The absolute URL of the web page to fetch (http:// or https://).')
                ->required(),
        ];
    }

    private function extractReadable(string $html, string $url): string
    {
        try {
            $readability = new Readability(new ReadabilityConfiguration([
                'originalURL' => $url,
                'fixRelativeURLs' => true,
            ]));

            $readability->parse($html);
        } catch (ParseException $e) {
            return $this->stripHtml($html);
        } catch (Throwable $e) {
            return $this->stripHtml($html);
        }

        $title = $readability->getTitle();
        $content = $readability->getContent();

        if ($content === null || $content === '') {
            return $this->stripHtml($html);
        }

        $text = $this->stripHtml($content);

        if ($title !== null && $title !== '') {
            return trim($title)."\n\n".$text;
        }

        return $text;
    }

    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('web_fetch');
    }

    private function maxBytes(): int
    {
        return $this->maxResponseBytes ?? (int) config('gail.tools.max_output_bytes.web_fetch', 30_000);
    }
}
