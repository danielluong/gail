<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'conversation_id' => Conversation::factory(),
            'user_id' => null,
            'agent' => 'ChatAgent',
            'role' => 'user',
            'variant_of' => null,
            'content' => fake()->paragraph(),
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => 'assistant']);
    }

    public function variantOf(ConversationMessage $original): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'conversation_id' => $original->conversation_id,
            'variant_of' => $original->id,
        ]);
    }
}
