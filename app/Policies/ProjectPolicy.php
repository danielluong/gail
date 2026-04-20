<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Authorization stub for projects.
 *
 * Gail runs single-user behind `EnsureLocalOnly` today, so every policy
 * method returns true unconditionally. The class exists so that a
 * future multi-user build has a single place to gate access — form
 * requests and controllers already go through `authorize()` here.
 *
 * When multi-user support lands, replace the `true` returns with checks
 * against the (nullable, for now) `$user` argument.
 */
class ProjectPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Project $project): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return true;
    }

    public function update(?User $user, Project $project): bool
    {
        return true;
    }

    public function delete(?User $user, Project $project): bool
    {
        return true;
    }
}
