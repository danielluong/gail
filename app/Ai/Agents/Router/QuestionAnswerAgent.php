<?php

namespace App\Ai\Agents\Router;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Specialist that handles factual / explanatory "what is / how does"
 * questions in the router workflow. Exposed as a plain Promptable so
 * the JSON endpoint can hit it directly; its {@see self::PROMPT} is
 * also composed into {@see RouterAgent} when
 * the classifier picks the "question" category for a chat turn.
 */
#[Temperature(0.3)]
#[MaxTokens(1024)]
#[Timeout(60)]
class QuestionAnswerAgent implements Agent
{
    use Promptable;

    public const PROMPT = <<<'PROMPT'
    You answer questions clearly and concisely.

    - Provide accurate explanations.
    - Use structured formatting when helpful.
    - Do not be overly verbose.
    PROMPT;

    public function instructions(): Stringable|string
    {
        return self::PROMPT;
    }
}
