<?php

namespace App\Ai\Agents;

use App\Ai\Context\ContextProvider;
use App\Models\Project;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Shared infrastructure for every agent in the app. Holds the project
 * binding, temperature override, tool discovery, context-provider
 * pipeline, and the final prompt composer. Subclasses declare their
 * own identity by implementing {@see toolsTag()} and
 * {@see basePrompt()} — they cannot override {@see instructions()},
 * which would silently bypass the context pipeline.
 *
 * The runtime attributes below are conservative defaults appropriate
 * for the general chat case. Specialized agents (low-temperature SQL
 * analyst, high-temperature limerick poet) override them with more
 * precise values.
 */
#[Temperature(0.7)]
#[MaxTokens(4096)]
#[MaxSteps(30)]
#[Timeout(300)]
abstract class BaseAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    protected ?Project $project = null;

    protected ?float $temperature = null;

    public function forProject(?int $projectId): static
    {
        if ($projectId !== null) {
            $this->project = Project::find($projectId);
        }

        return $this;
    }

    public function withTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    /**
     * Default model for this agent, resolved from the active provider's
     * `default_model` in config/ai.php so flipping `ai.default` picks a
     * matching model automatically. An explicit model passed to
     * prompt()/stream() still wins — see
     * Laravel\Ai\Promptable::getProvidersAndModels.
     */
    public function model(): ?string
    {
        $provider = config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $model = config("ai.providers.{$provider}.default_model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($this->temperature === null) {
            return [];
        }

        return ['temperature' => $this->temperature];
    }

    /**
     * Compose the agent's final system prompt from {@see basePrompt()}
     * and every registered {@see ContextProvider}. Marked final because
     * overriding this is the one mistake that silently breaks the
     * context pipeline — subclasses override basePrompt() instead.
     */
    final public function instructions(): string
    {
        $base = (string) $this->basePrompt();
        $context = $this->buildContext();

        if ($context !== '') {
            return $base."\n\n".$context;
        }

        return $base;
    }

    /**
     * Tools are discovered via container tags bound in AiServiceProvider.
     * Every BaseAgent automatically receives the `ai.tools.core` tag
     * (ManageNotes, SearchProjectDocuments) — these mirror the read side
     * of the context pipeline so every agent can also write notes and
     * search project documents. The agent-specific tag(s) returned by
     * {@see toolsTag()} are then merged on top.
     *
     * When {@see toolsTag()} returns a list of tags, the agent composes
     * the union of every tag's bindings. Classes that appear under more
     * than one tag are deduplicated so the model sees each tool once.
     *
     * laravel/ai's gateway contracts type $tools as a strict array, so
     * the tagged iterables must be materialised here.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        $tools = [];
        $seen = [];

        $tags = array_merge(['ai.tools.core'], (array) $this->toolsTag());

        foreach ($tags as $tag) {
            foreach (app()->tagged($tag) as $tool) {
                if (isset($seen[$tool::class])) {
                    continue;
                }

                $seen[$tool::class] = true;
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The system prompt body specific to this agent. Kept abstract so
     * every subclass declares its own prompt explicitly — no accidental
     * empty prompts via inheritance.
     */
    abstract protected function basePrompt(): Stringable|string;

    /**
     * The container tag, or list of tags, whose bound tools should be
     * exposed to this agent. Abstract so every agent opts into a tool
     * set by name instead of inheriting a shared default — prevents
     * accidental exposure of tools the agent wasn't designed for.
     * Returning a list composes the union of each tag, with duplicate
     * classes collapsed in {@see tools()}.
     *
     * @return string|list<string>
     */
    abstract protected function toolsTag(): string|array;

    protected function buildContext(): string
    {
        $sections = [];

        foreach ($this->contextProviders() as $provider) {
            $rendered = $provider->render($this->project);

            if ($rendered !== null && $rendered !== '') {
                $sections[] = $rendered;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * Resolve the ordered list of context providers contributing to
     * the system prompt. Providers are discovered via the
     * `ai.context_providers` container tag, which lets new context
     * sources be registered without touching the agent.
     *
     * @return iterable<ContextProvider>
     */
    protected function contextProviders(): iterable
    {
        return app()->tagged('ai.context_providers');
    }
}
