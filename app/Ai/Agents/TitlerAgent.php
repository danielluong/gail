<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * One-shot agent used to summarize the first turn of a conversation into
 * a short title. Deliberately tool-free and uses the provider's cheapest
 * text model so a title call adds <1s after the user's response streams.
 */
#[UseCheapestModel]
#[Temperature(0.2)]
#[MaxTokens(24)]
#[Timeout(15)]
class TitlerAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You write short titles for chat conversations.

Given the first user question and the assistant's reply, respond with a
concise title of 3-6 words summarizing the topic. Output only the title —
no quotes, no trailing punctuation, no preface.
PROMPT;
    }
}
