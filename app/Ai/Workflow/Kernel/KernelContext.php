<?php

namespace App\Ai\Workflow\Kernel;

use App\Ai\Agents\AgentType;
use App\Ai\Agents\BaseAgent;
use App\Ai\Workflow\Dto\CriticVerdict;
use Closure;

/**
 * Mutable shared state passed to every plugin call. Three responsibilities:
 *
 * 1. **Identity.** The original input string + the pipeline the router
 *    selected — both immutable once set.
 * 2. **Cross-cutting metadata.** Bag for context that doesn't belong in
 *    the per-step input dict: the agent_type hint from the chat UI, the
 *    Critic's verdict on the previous pass (read by retry-aware steps),
 *    the SSE phase-emit closure when streaming, the chat-UI facade
 *    {@see BaseAgent} that owns conversation persistence.
 * 3. **Trace + retry counter.** Append-only execution log + the
 *    orchestrator's retry guard (Kernel caps retries at one).
 *
 * The seven well-known metadata keys — `agent_type`, `facade`,
 * `yieldPhase`, `attachments`, `model`, `classification`,
 * `critic_feedback` — are exposed through typed accessors so new
 * contributors can discover them via IDE autocomplete and PHPStan
 * narrows their types at read-time. Less common keys still go through
 * {@see set()}/{@see get()}/{@see has()}, which stay as the
 * extension surface for plugin-specific state.
 */
final class KernelContext
{
    public const KEY_AGENT_TYPE = 'agent_type';

    public const KEY_FACADE = 'facade';

    public const KEY_YIELD_PHASE = 'yieldPhase';

    public const KEY_ATTACHMENTS = 'attachments';

    public const KEY_MODEL = 'model';

    public const KEY_CLASSIFICATION = 'classification';

    public const KEY_CRITIC_FEEDBACK = 'critic_feedback';

    public ?string $selectedPipeline = null;

    public int $retryCount = 0;

    /** @var array<string, mixed> */
    public array $metadata = [];

    /** @var list<array{plugin: string, type: string, duration_ms: float}> */
    public array $trace = [];

    public function __construct(
        public readonly string $originalInput,
    ) {}

    public function set(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    public function recordTrace(string $plugin, string $type, float $durationMs): void
    {
        $this->trace[] = [
            'plugin' => $plugin,
            'type' => $type,
            'duration_ms' => $durationMs,
        ];
    }

    /* ---- Typed accessors for the well-known keys ---------------------- */

    public function setAgentType(AgentType $type): void
    {
        $this->metadata[self::KEY_AGENT_TYPE] = $type;
    }

    /**
     * Returns the agent-type hint that Mode A of the router uses to
     * bypass the classifier. Coerces a raw string value back to the
     * enum so legacy callers that passed a string through
     * {@see set()} still get a typed read.
     */
    public function agentType(): ?AgentType
    {
        $raw = $this->metadata[self::KEY_AGENT_TYPE] ?? null;

        if ($raw === null) {
            return null;
        }

        if ($raw instanceof AgentType) {
            return $raw;
        }

        return is_string($raw) ? AgentType::tryFrom($raw) : null;
    }

    public function setFacade(?BaseAgent $agent): void
    {
        $this->metadata[self::KEY_FACADE] = $agent;
    }

    public function facade(): ?BaseAgent
    {
        $raw = $this->metadata[self::KEY_FACADE] ?? null;

        return $raw instanceof BaseAgent ? $raw : null;
    }

    public function setYieldPhase(?Closure $emit): void
    {
        $this->metadata[self::KEY_YIELD_PHASE] = $emit;
    }

    public function yieldPhase(): ?Closure
    {
        $raw = $this->metadata[self::KEY_YIELD_PHASE] ?? null;

        return $raw instanceof Closure ? $raw : null;
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    public function setAttachments(array $attachments): void
    {
        $this->metadata[self::KEY_ATTACHMENTS] = $attachments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachments(): array
    {
        $raw = $this->metadata[self::KEY_ATTACHMENTS] ?? [];

        return is_array($raw) ? array_values($raw) : [];
    }

    public function setModelOverride(?string $model): void
    {
        $this->metadata[self::KEY_MODEL] = $model;
    }

    public function modelOverride(): ?string
    {
        $raw = $this->metadata[self::KEY_MODEL] ?? null;

        return is_string($raw) ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    public function setClassification(array $classification): void
    {
        $this->metadata[self::KEY_CLASSIFICATION] = $classification;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function classification(): ?array
    {
        $raw = $this->metadata[self::KEY_CLASSIFICATION] ?? null;

        return is_array($raw) ? $raw : null;
    }

    public function setCriticFeedback(CriticVerdict $verdict): void
    {
        // Stored as the serialized array so step input dicts and
        // retry strategies can read `critic_feedback.missing` etc
        // without rehydrating the DTO.
        $this->metadata[self::KEY_CRITIC_FEEDBACK] = $verdict->toArray();
    }

    public function criticFeedback(): ?CriticVerdict
    {
        $raw = $this->metadata[self::KEY_CRITIC_FEEDBACK] ?? null;

        return is_array($raw) ? CriticVerdict::fromArray($raw) : null;
    }
}
