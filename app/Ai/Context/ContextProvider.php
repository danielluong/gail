<?php

namespace App\Ai\Context;

use App\Models\Project;

interface ContextProvider
{
    /**
     * Return a markdown section to append to the agent's system prompt,
     * or null if the provider has nothing to contribute for the given
     * project. An agent with no active project receives null here.
     */
    public function render(?Project $project): ?string;
}
