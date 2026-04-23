<?php

namespace App\Http\Controllers;

use App\Actions\Router\RunRouterExample;
use App\Actions\Router\StreamRouterResponse;
use App\Http\Requests\RouteInputRequest;
use Illuminate\Http\JsonResponse;

/**
 * JSON entrypoint for the Classifier → Router → Specialist workflow.
 * Runs the pipeline synchronously and returns the classified
 * category, the confidence, the chosen specialist's name, and its
 * response.
 *
 * The chat UI streams through {@see StreamRouterResponse}
 * instead; this controller exists for scripts, tests, and any
 * programmatic caller that wants the verdict + answer in one blob
 * without piecing together SSE frames.
 */
class RouterController extends Controller
{
    public function route(RouteInputRequest $request, RunRouterExample $action): JsonResponse
    {
        $result = $action->execute((string) $request->validated('input'));

        return response()->json($result);
    }
}
