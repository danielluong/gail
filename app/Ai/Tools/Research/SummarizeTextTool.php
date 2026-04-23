<?php

namespace App\Ai\Tools\Research;

use App\Ai\Agents\Research\LlmCallerAgent;
use App\Ai\Contracts\DisplayableTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Calls the LLM to condense a long body of text into a focused summary.
 * The Researcher uses this after FetchPageTool so that it doesn't have to
 * re-prompt with a page's full contents — feeding verbose pages back
 * into the model is what causes context windows to blow up.
 */
class SummarizeTextTool implements DisplayableTool, Tool
{
    protected const DEFAULT_MAX_LENGTH = 500;

    protected const MAX_INPUT_CHARS = 20_000;

    public function label(): string
    {
        return 'Summarized content';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
        Condense a long block of text into a focused summary (default
        ~500 characters). Use this after FetchPageTool to capture the
        relevant points of a page before deciding whether to extract
        structured facts from it.
        DESCRIPTION;
    }

    public function handle(Request $request): Stringable|string
    {
        $text = trim((string) ($request['text'] ?? ''));
        $maxLength = $this->normalizeMaxLength($request['max_length'] ?? null);

        if ($text === '') {
            return 'Error: No text provided.';
        }

        return $this->summarize($text, $maxLength);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('The text to summarize.')
                ->required(),
            'max_length' => $schema->integer()
                ->description('Approximate character budget for the summary. Defaults to 500.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * Direct-call API for summarising text without going through the Tool
     * contract. Returns the summary, or a short error string on failure
     * (never throws, so callers don't need try/catch).
     */
    public function summarize(string $text, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        if ($text === '') {
            return '';
        }

        $truncated = mb_substr($text, 0, self::MAX_INPUT_CHARS);
        $budget = max(80, min($maxLength, 2_000));

        $prompt = <<<PROMPT
        Summarize the following text in no more than {$budget} characters.
        Extract the key points and preserve important facts, numbers, and
        names. Do not add commentary, disclaimers, or a leading "Summary:"
        label. Plain prose, not bullet points.

        ---
        {$truncated}
        PROMPT;

        try {
            $response = LlmCallerAgent::make()->prompt($prompt);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('research.summarize_failed', [
                'error' => $e->getMessage(),
            ]);

            return 'Error: Summarization failed — '.$e->getMessage();
        }

        return trim($response->text);
    }

    private function normalizeMaxLength(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_MAX_LENGTH;
        }

        return max(80, min((int) $value, 2_000));
    }
}
