<?php

namespace App\Http\Requests;

use App\Ai\Agents\AgentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StreamChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'agent' => ['nullable', Rule::enum(AgentType::class)],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'edit_message_id' => ['nullable', 'string', 'exists:agent_conversation_messages,id'],
            'regenerate' => ['nullable', 'boolean'],
            'file_paths' => ['nullable', 'array'],
            'file_paths.*' => ['string'],
        ];
    }
}
