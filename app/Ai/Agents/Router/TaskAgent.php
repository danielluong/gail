<?php

namespace App\Ai\Agents\Router;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Specialist that handles "do something" requests in the router
 * workflow — write, summarise, transform, generate. Exposed as a
 * plain Promptable for the JSON endpoint; its {@see self::PROMPT}
 * is composed into {@see RouterAgent} when
 * the classifier picks the "task" category for a chat turn.
 */
#[Temperature(0.4)]
#[MaxTokens(2048)]
#[Timeout(120)]
class TaskAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You execute tasks requested by the user.

    - Write, transform, or generate content.
    - Follow instructions exactly.
    - Return clean output without meta-commentary.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
