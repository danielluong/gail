<?php

namespace App\Actions\Chat;

use App\Ai\Agents\AgentType;
use App\Ai\Agents\BaseAgent;
use App\Ai\Context\ProjectScope;
use App\Models\Conversation;
use App\Support\GuestUser;

class ResolveChatAgent
{
    public function __construct(
        private readonly ProjectScope $scope,
    ) {}

    /**
     * Build a configured agent for a request, resolving the active
     * project id from either the conversation being continued or the
     * explicit project_id parameter. Returns [agent, projectId].
     *
     * @return array{0: BaseAgent, 1: ?int}
     */
    public function execute(
        ?string $conversationId,
        ?int $requestedProjectId,
        ?float $temperature,
        ?string $agentKey = null,
    ): array {
        $class = (AgentType::tryFrom($agentKey ?? '') ?? AgentType::Default)->agentClass();
        $agent = new $class;
        $guest = new GuestUser;

        if ($conversationId !== null) {
            $agent->continue($conversationId, as: $guest);

            $projectId = Conversation::where('id', $conversationId)->value('project_id')
                ?? $requestedProjectId;
        } else {
            $agent->forUser($guest);
            $projectId = $requestedProjectId;
        }

        $agent->forProject($projectId);
        $this->scope->set($projectId);

        if ($temperature !== null) {
            $agent->withTemperature($temperature);
        }

        return [$agent, $projectId];
    }
}
