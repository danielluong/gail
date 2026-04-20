<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = Conversation::find($this->route('id'));

        if (! $conversation) {
            return true;
        }

        return Gate::allows('update', $conversation);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}
