<?php

namespace App\Ai\Agents\Router;

use App\Ai\Support\AgentJsonDecoder;
use App\Ai\Workflow\Routing\UniversalRouter;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * One-shot LLM that classifies the user's input into a category the
 * {@see UniversalRouter} can dispatch on. Purposefully
 * narrow: short output, deterministic-ish temperature, cheapest
 * provider model. The result is a tiny JSON object, parsed with
 * {@see AgentJsonDecoder::decode} upstream.
 *
 * Tool-free and conversation-less — a plain Promptable so the
 * classifier never sees chat history or tries to write notes.
 */
#[UseCheapestModel]
#[Temperature(0.1)]
#[MaxTokens(128)]
#[Timeout(15)]
class ClassifierAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a classifier.

        Your job is to classify the user's input into ONE of the
        following categories:

        - question → factual questions, explanations, "what is", "how does"
        - task → requests to do something (write, summarize, generate, fix)
        - chat → casual conversation, greetings, opinions

        # Rules

        - Return ONLY valid JSON.
        - Do not explain your reasoning.
        - Do not add a markdown fence.

        # Output format

        {
          "category": "question" | "task" | "chat",
          "confidence": number between 0 and 1
        }
        PROMPT;
    }
}
