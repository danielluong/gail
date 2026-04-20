<?php

namespace App\Ai\Contracts;

/**
 * A tool that can render a short human-readable label for its activity,
 * used by the chat UI to describe what a tool call accomplished
 * (e.g. "Checked the weather"). Every tool registered on any
 * `ai.tools.*` container tag should implement this so the frontend
 * label map stays in sync with the backend tool set.
 */
interface DisplayableTool
{
    public function label(): string;
}
