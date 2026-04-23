<?php

namespace App\Ai\Tools\Research;

use App\Ai\Agents\Research\LlmCallerAgent;
use App\Ai\Contracts\DisplayableTool;
use App\Ai\Support\AgentJsonDecoder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Calls the LLM to extract structured facts from messy text according to
 * a plain-English schema instruction supplied by the Researcher (e.g.
 * "Extract pros, cons, and key statistics"). The output is JSON so the
 * Researcher can assemble it into its final findings object.
 */
class ExtractFactsTool implements DisplayableTool, Tool
{
    protected const MAX_INPUT_CHARS = 20_000;

    public function label(): string
    {
        return 'Extracted facts';
    }

    public function description(): Stringable|string
    {
        return <<<'DESCRIPTION'
        Extract structured facts from a block of text. Provide a
        plain-English schema describing what you want (e.g. "Extract the
        pros, cons, and key statistics"); the tool returns JSON matching
        that shape. Use this after SummarizeTextTool when you need
        machine-readable data to merge into your findings.
        DESCRIPTION;
    }

    public function handle(Request $request): Stringable|string
    {
        $text = trim((string) ($request['text'] ?? ''));
        $schemaInstruction = trim((string) ($request['schema'] ?? ''));

        if ($text === '') {
            return json_encode(['error' => 'No text provided.']);
        }

        if ($schemaInstruction === '') {
            return json_encode(['error' => 'No schema instruction provided.']);
        }

        $result = $this->extract($text, $schemaInstruction);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('The source text to extract from.')
                ->required(),
            'schema' => $schema->string()
                ->description('Plain-English description of the JSON shape to return (e.g. "Extract pros, cons, and key statistics").')
                ->required(),
        ];
    }

    /**
     * Direct-call API. Returns a PHP array parsed from the model's JSON
     * reply, or `['error' => '...']` on failure — never throws.
     *
     * @return array<string, mixed>
     */
    public function extract(string $text, string $schemaInstruction): array
    {
        $truncated = mb_substr($text, 0, self::MAX_INPUT_CHARS);

        $prompt = <<<PROMPT
        Extract structured facts from the text below.

        Schema (what to return):
        {$schemaInstruction}

        Rules:
        - Respond with a single JSON object only.
        - No markdown fencing, no preamble, no trailing commentary.
        - Use double-quoted strings and arrays where appropriate.
        - If a field cannot be determined from the text, use null or [].

        Text:
        ---
        {$truncated}
        PROMPT;

        try {
            $response = LlmCallerAgent::make()->prompt($prompt);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('research.extract_failed', [
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'Extraction failed: '.$e->getMessage()];
        }

        $parsed = AgentJsonDecoder::decode($response->text);

        if ($parsed === null) {
            return [
                'error' => 'Model did not return valid JSON.',
                'raw' => mb_substr($response->text, 0, 1_000),
            ];
        }

        return $parsed;
    }
}
