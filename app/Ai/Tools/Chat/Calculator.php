<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use NXP\MathExecutor;
use Stringable;
use Throwable;

class Calculator implements DisplayableTool, Tool
{
    public function label(): string
    {
        return 'Did the math';
    }

    public function description(): Stringable|string
    {
        return 'Evaluate a mathematical expression and return the exact result. Use this for any arithmetic — LLMs silently mis-calculate, so prefer this over doing the math yourself. Supports +, -, *, /, %, ^, parentheses, and common functions like sqrt, abs, round, floor, ceil, min, max, sin, cos, tan, log, ln, exp, pi(), and e(). Example: "(87.50 * 1.18) / 5" for tax+tip split five ways.';
    }

    public function handle(Request $request): Stringable|string
    {
        $expression = trim((string) ($request['expression'] ?? ''));

        if ($expression === '') {
            return 'Error: No expression provided.';
        }

        try {
            $result = (new MathExecutor)->execute($expression);
        } catch (Throwable $e) {
            return "Error: Could not evaluate \"{$expression}\" — {$e->getMessage()}";
        }

        return "{$expression} = ".$this->formatResult($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expression' => $schema->string()
                ->description('The mathematical expression to evaluate, e.g. "2 + 2 * 3", "sqrt(144) + 5", or "(87.50 * 1.18) / 5".')
                ->required(),
        ];
    }

    private function formatResult(mixed $result): string
    {
        if (is_int($result)) {
            return (string) $result;
        }

        if (is_float($result)) {
            if (is_nan($result)) {
                return 'NaN';
            }

            if (is_infinite($result)) {
                return $result > 0 ? 'Infinity' : '-Infinity';
            }

            $rounded = round($result, 10);

            return rtrim(rtrim(sprintf('%.10F', $rounded), '0'), '.');
        }

        return (string) $result;
    }
}
