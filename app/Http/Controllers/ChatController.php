<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ResolveChatAgent;
use App\Actions\Chat\TruncateMessagesFromMessage;
use App\Ai\Agents\AgentType;
use App\Http\Requests\StreamChatRequest;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\OllamaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Ai\Transcription;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('chat', [
            'agents' => AgentType::options(),
            'projects' => Project::query()
                ->orderBy('name')
                ->get(['id', 'name', 'system_prompt']),
            'conversations' => Conversation::query()
                ->orderByDesc('is_pinned')
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'project_id', 'parent_id', 'is_pinned', 'updated_at']),
        ]);
    }

    public function models(OllamaClient $ollama): JsonResponse
    {
        $provider = (string) config('ai.default', 'openai');

        if ($provider === 'ollama') {
            return response()->json($ollama->listModels());
        }

        $models = (array) config("ai.providers.{$provider}.available_models", []);

        return response()->json(array_values(array_filter(
            $models,
            fn ($model) => is_string($model) && $model !== '',
        )));
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $relative = $file->store('uploads', 'local');
        $filename = basename($relative);

        return response()->json([
            'name' => $file->getClientOriginalName(),
            'path' => storage_path('app/private/'.$relative),
            'url' => route('uploads.show', ['filename' => $filename]),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
        ]);
    }

    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $response = Transcription::fromUpload($request->file('audio'))
            ->when($request->input('language'), fn ($pending, $language) => $pending->language($language))
            ->generate();

        return response()->json(['text' => $response->text]);
    }

    public function show(string $filename): BinaryFileResponse
    {
        $path = storage_path('app/private/uploads/'.$filename);

        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path);
    }

    public function stream(
        StreamChatRequest $request,
        ResolveChatAgent $resolveAgent,
        TruncateMessagesFromMessage $truncate,
    ): StreamedResponse {
        $conversationId = $request->validated('conversation_id');
        $temperature = $request->validated('temperature');

        [$agent, $projectId] = $resolveAgent->execute(
            conversationId: $conversationId,
            requestedProjectId: $request->validated('project_id'),
            temperature: $temperature !== null ? (float) $temperature : null,
            agentKey: $request->validated('agent'),
        );

        $editMessageId = $request->validated('edit_message_id');

        if ($editMessageId !== null && $conversationId !== null) {
            $truncate->execute($conversationId, $editMessageId);
        }

        // Every agent declares its own streamingActionClass() — plain
        // BaseAgents default to StreamChatResponse (wraps in a
        // SingleAgentPipeline, no Critic), MultiAgentFacade subclasses
        // override to point at their workflow's streaming action. The
        // SSE contract is shared across every workflow, so the UI
        // cannot tell them apart — only the set of phase frames differs.
        return app($agent::streamingActionClass())->execute(
            agent: $agent,
            message: (string) $request->validated('message'),
            filePaths: $request->validated('file_paths') ?? [],
            model: $request->validated('model'),
            projectId: $projectId,
            regenerate: (bool) $request->validated('regenerate'),
        );
    }
}
