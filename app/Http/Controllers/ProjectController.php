<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function store(StoreProjectRequest $request)
    {
        Project::create($request->validated());

        return response()->noContent();
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $project->update($request->validated());

        return response()->noContent();
    }

    public function destroy(Project $project)
    {
        DB::transaction(function () use ($project) {
            $project->conversations()->delete();
            $project->delete();
        });

        return response()->noContent();
    }
}
