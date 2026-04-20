<?php

namespace App\Http\Middleware;

use App\Ai\Contracts\DisplayableTool;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'toolLabels' => $this->toolLabels(),
            'transcriptionEnabled' => config('ai.default_for_transcription') !== null,
            'aiProvider' => (string) config('ai.default', ''),
        ];
    }

    /**
     * Tool container tags whose members contribute DisplayableTool
     * labels to the chat UI. One tag per agent keeps each agent's tool
     * set isolated; the UI still needs labels for every tool the user
     * might invoke, so they are unioned here.
     *
     * @var list<string>
     */
    private const TOOL_LABEL_TAGS = [
        'ai.tools.core',
        'ai.tools.chat',
        'ai.tools.mysql_database',
    ];

    /**
     * Build a map of tool class short-name → human label for the chat UI,
     * derived from every tool registered on the listed container tags
     * that implements DisplayableTool. Keeps frontend labels in sync with
     * backend tool registrations automatically.
     *
     * @return array<string, string>
     */
    private function toolLabels(): array
    {
        $labels = [];

        foreach (self::TOOL_LABEL_TAGS as $tag) {
            foreach (app()->tagged($tag) as $tool) {
                if ($tool instanceof DisplayableTool) {
                    $labels[class_basename($tool)] = $tool->label();
                }
            }
        }

        return $labels;
    }
}
