<?php

namespace App\Ai\Context;

/**
 * Request-scoped holder for the active project id. Tools that need to
 * filter by project resolve this from the container instead of exposing
 * their own mutable setter, so scope flows through a single seam.
 */
class ProjectScope
{
    private ?int $projectId = null;

    public function set(?int $projectId): void
    {
        $this->projectId = $projectId;
    }

    public function id(): ?int
    {
        return $this->projectId;
    }
}
