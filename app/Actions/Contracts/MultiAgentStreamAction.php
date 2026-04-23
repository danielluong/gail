<?php

namespace App\Actions\Contracts;

use App\Ai\Agents\BaseAgent;
use App\Ai\Agents\MultiAgentFacade;
use App\Http\Controllers\ChatController;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract for every chat-UI streaming action that drives a
 * multi-agent workflow (Research, Router, and whatever comes next).
 *
 * Consumed by {@see ChatController::stream()},
 * which dispatches to the implementation named by the facade's
 * {@see MultiAgentFacade::streamingActionClass()}.
 * Keeping the signature in one place means the controller can route
 * to any future workflow via `app($facade::streamingActionClass())`
 * without another `instanceof` branch.
 */
interface MultiAgentStreamAction
{
    /**
     * @param  list<string>  $filePaths
     */
    public function execute(
        BaseAgent $agent,
        string $message,
        array $filePaths,
        ?string $model,
        ?int $projectId,
        bool $regenerate = false,
    ): StreamedResponse;
}
