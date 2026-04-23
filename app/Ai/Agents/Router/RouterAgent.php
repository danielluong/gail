<?php

namespace App\Ai\Agents\Router;

use App\Actions\Router\StreamRouterResponse;
use App\Ai\Agents\MultiAgentFacade;
use App\Ai\Agents\Research\ResearchAgent;
use App\Enums\InputCategory;
use Stringable;

/**
 * Chat-UI-facing facade for the Classifier → Router → Specialist
 * workflow. Extends {@see MultiAgentFacade} so conversation
 * persistence, project binding, message history, title generation,
 * and the tool-free facade shape all come for free.
 *
 * At runtime, {@see StreamRouterResponse} runs the ClassifierAgent
 * synchronously *before* calling ->stream() on this agent, stashes
 * the verdict via {@see withCategory()} + {@see withConfidence()},
 * and the injected category picks the corresponding specialist's
 * PROMPT const via {@see basePrompt()}. The visible stream is just
 * the chosen specialist writing the answer — which is what the end
 * user sees in the chat bubble.
 *
 * Parallel to {@see ResearchAgent} — same
 * "MultiAgentFacade + fluent setter + basePrompt composes a
 * specialist's PROMPT" pattern. The classifier runs outside the
 * Promptable contract (no tool loop).
 */
class RouterAgent extends MultiAgentFacade
{
    protected ?InputCategory $category = null;

    protected ?float $confidence = null;

    protected ?string $classifierWarning = null;

    public static function streamingActionClass(): string
    {
        return StreamRouterResponse::class;
    }

    /**
     * Stash the classifier's chosen category so basePrompt() picks
     * the matching specialist's PROMPT const on the next stream call.
     */
    public function withCategory(?InputCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Stash the classifier's reported confidence so basePrompt() can
     * surface it to the specialist as a classifier-header hint.
     */
    public function withConfidence(?float $confidence): static
    {
        $this->confidence = $confidence;

        return $this;
    }

    /**
     * Stash an operator-visible warning (malformed classifier JSON,
     * invalid category, etc.) so the specialist's prompt can mention
     * that the classification was a fallback rather than a verdict.
     */
    public function withClassifierWarning(?string $message): static
    {
        $this->classifierWarning = $message;

        return $this;
    }

    protected function basePrompt(): Stringable|string
    {
        $effective = $this->category ?? InputCategory::Chat;

        $specialistPrompt = match ($effective) {
            InputCategory::Question => QuestionAnswerAgent::PROMPT,
            InputCategory::Task => TaskAgent::PROMPT,
            InputCategory::Chat => ChatAgent::PROMPT,
        };

        // Skip the classifier header entirely when the caller never
        // set a category / confidence / warning — that's the "bare"
        // case where the facade is being instantiated without router
        // context (tests, defaults). Any explicit signal from the
        // streaming action surfaces the header so the specialist
        // sees why it was picked.
        if ($this->category === null && $this->confidence === null && $this->classifierWarning === null) {
            return $specialistPrompt;
        }

        return $specialistPrompt."\n\n".$this->buildClassifierHeader($effective);
    }

    /**
     * Surface the classifier's verdict as a small header appended to
     * the specialist's prompt. Gives the specialist context about why
     * it was picked, and lets the prompt author flag fallback
     * classifications by inspecting the confidence / warning fields.
     */
    private function buildClassifierHeader(InputCategory $category): string
    {
        $parts = ['# Classifier verdict', 'Category: '.$category->value];

        if ($this->confidence !== null) {
            $parts[] = 'Confidence: '.number_format($this->confidence, 2);
        }

        if ($this->classifierWarning !== null && $this->classifierWarning !== '') {
            $parts[] = 'Warning: '.$this->classifierWarning;
        }

        return implode("\n", $parts);
    }
}
