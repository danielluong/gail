<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(
            $project->documents()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'status', 'chunk_count', 'size', 'mime_type', 'created_at'])
        );
    }

    public function store(StoreDocumentRequest $request, Project $project): JsonResponse
    {
        $file = $request->file('file');
        $relative = $file->store("documents/{$project->id}", 'local');

        $document = $project->documents()->create([
            'name' => $file->getClientOriginalName(),
            'disk_path' => $relative,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => DocumentStatus::Pending,
        ]);

        ProcessDocument::dispatch($document);

        return response()->json([
            'id' => $document->id,
            'name' => $document->name,
            'status' => $document->status,
            'size' => $document->size,
        ], 201);
    }

    public function destroy(Project $project, Document $document): Response
    {
        if ($document->project_id !== $project->id) {
            abort(404);
        }

        Storage::disk('local')->delete($document->disk_path);
        $document->delete();

        return response()->noContent();
    }
}
