<?php

namespace App\Ai\Context;

use App\Models\Project;

class ProjectContext implements ContextProvider
{
    public function render(?Project $project): ?string
    {
        if ($project === null) {
            return null;
        }

        $sections = [
            "## Current Project\nYou are working in the project \"{$project->name}\" (ID: {$project->id}).",
        ];

        if ($project->system_prompt) {
            $sections[] = "## Project Instructions\nFollow these project-specific instructions:\n{$project->system_prompt}";
        }

        return implode("\n\n", $sections);
    }
}
