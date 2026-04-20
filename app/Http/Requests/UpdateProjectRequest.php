<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = Project::find($this->route('id'));

        if (! $project) {
            return true;
        }

        return Gate::allows('update', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
