<?php

namespace App\Models;

use App\Support\Formatters\AttachmentFormatter;
use App\Support\Formatters\ToolCallFormatter;
use App\Support\ModelPricing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ?list<array<string, mixed>> $attachments
 * @property ?list<array<string, mixed>> $tool_calls
 * @property ?list<array<string, mixed>> $tool_results
 * @property ?array<string, mixed> $usage
 * @property ?array<string, mixed> $meta
 */
#[Fillable([
    'id',
    'conversation_id',
    'user_id',
    'agent',
    'role',
    'variant_of',
    'content',
    'attachments',
    'tool_calls',
    'tool_results',
    'usage',
    'meta',
])]
class ConversationMessage extends Model
{
    use HasFactory;

    protected $table = 'agent_conversation_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'usage' => 'array',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Shape a message record for the chat UI. Mirrors the event shape the
     * frontend reducer already handles during a live SSE stream, so a
     * refreshed history and a just-streamed response render identically.
     *
     * @return array{
     *     id: string,
     *     role: string,
     *     content: ?string,
     *     attachments: list<array{name: string, type: ?string, url: ?string}>,
     *     toolCalls: list<array{tool_id: string, tool_name: string, arguments: array<string, mixed>, result: ?string}>,
     *     phases: list<array<string, mixed>>,
     *     model: ?string,
     *     usage: ?array{prompt_tokens: int, completion_tokens: int},
     *     cost: ?float,
     *     created_at: ?string,
     * }
     */
    public function toChatUiArray(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $model = $meta['model'] ?? null;
        $modelName = is_string($model) && $model !== '' ? $model : null;
        $usage = $this->usageForUi();

        $rawPhases = $meta['phases'] ?? [];
        $phases = is_array($rawPhases)
            ? array_values(array_filter($rawPhases, 'is_array'))
            : [];

        return [
            'id' => (string) $this->id,
            'role' => (string) $this->role,
            'content' => $this->content,
            'attachments' => app(AttachmentFormatter::class)->format($this->attachments),
            'toolCalls' => app(ToolCallFormatter::class)->format($this->tool_calls, $this->tool_results),
            'phases' => $phases,
            'model' => $modelName,
            'usage' => $usage,
            'cost' => app(ModelPricing::class)->costFor($modelName, $usage),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Coerce the raw usage JSON to a normalized shape the UI can rely on,
     * or null if the row has no usage data (user messages, old rows).
     *
     * @return ?array{prompt_tokens: int, completion_tokens: int, cache_write_input_tokens: int, cache_read_input_tokens: int, reasoning_tokens: int}
     */
    private function usageForUi(): ?array
    {
        if (! is_array($this->usage) || $this->usage === []) {
            return null;
        }

        return [
            'prompt_tokens' => (int) ($this->usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($this->usage['completion_tokens'] ?? 0),
            'cache_write_input_tokens' => (int) ($this->usage['cache_write_input_tokens'] ?? 0),
            'cache_read_input_tokens' => (int) ($this->usage['cache_read_input_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($this->usage['reasoning_tokens'] ?? 0),
        ];
    }
}
