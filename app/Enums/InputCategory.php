<?php

namespace App\Enums;

/**
 * Intent categories the Router workflow's classifier emits, and the
 * universal router dispatches on. The backing string values are what
 * the classifier prompt asks the LLM to return and what the JSON
 * endpoint echoes back on `response.category`.
 *
 * Values are lowercase-trimmed at parse time via {@see self::tryFromString},
 * so models that return `"  Question\n"` or `"TASK"` still resolve.
 */
enum InputCategory: string
{
    case Question = 'question';
    case Task = 'task';
    case Chat = 'chat';

    /**
     * Parse a raw classifier value into an enum instance. Trims
     * surrounding whitespace and lowercases so small LLM formatting
     * quirks don't force the orchestrator's fallback path.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalised = strtolower(trim($value));

        if ($normalised === '') {
            return null;
        }

        return self::tryFrom($normalised);
    }
}
