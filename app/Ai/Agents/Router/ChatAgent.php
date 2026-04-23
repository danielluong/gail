<?php

namespace App\Ai\Agents\Router;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Specialist that handles casual conversation in the router workflow
 * — greetings, small talk, opinions. Also the low-confidence fallback
 * target when the classifier isn't sure enough to route elsewhere.
 *
 * Sits under the `Router\` sub-namespace so it doesn't collide with
 * the project's primary chat-UI agent {@see \App\Ai\Agents\ChatAgent};
 * call sites always use the fully-qualified name or an aliased import.
 */
#[Temperature(0.7)]
#[MaxTokens(512)]
#[Timeout(60)]
class ChatAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You are a friendly conversational assistant.

    - Be natural and engaging.
    - Keep responses short.
    - Do not over-explain.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
