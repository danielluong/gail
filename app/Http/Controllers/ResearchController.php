<?php

namespace App\Http\Controllers;

use App\Actions\Research\RunResearchAssistant;
use App\Actions\Research\StreamResearchResponse;
use App\Http\Requests\RunResearchRequest;
use Illuminate\Http\JsonResponse;

/**
 * JSON entrypoint for the multi-agent research pipeline. Runs the full
 * Researcher → Editor → Critic loop (with one retry on critic rejection)
 * synchronously and returns the bundle as JSON.
 *
 * For the streaming chat-UI experience the same pipeline runs through
 * {@see StreamResearchResponse}; this controller
 * exists for scripts, tests, and any frontend that wants the unretried
 * pipeline output without piecing together SSE frames.
 */
class ResearchController extends Controller
{
    public function research(RunResearchRequest $request, RunResearchAssistant $action): JsonResponse
    {
        $result = $action->execute((string) $request->validated('query'));

        return response()->json($result);
    }
}
