<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Context\ProjectScope;
use App\Ai\Contracts\DisplayableTool;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProjectDocuments implements DisplayableTool, Tool
{
    public function __construct(
        private readonly ProjectScope $scope,
    ) {}

    public function label(): string
    {
        return 'Searched project documents';
    }

    public function description(): Stringable|string
    {
        return 'Search uploaded project documents by meaning to find relevant passages. Use this when the user asks about project-specific knowledge, references files they uploaded, or asks questions that might be answered by their project documents. Returns the most relevant text passages with source attribution.';
    }

    public function handle(Request $request): Stringable|string
    {
        $projectId = $this->scope->id();

        if ($projectId === null) {
            return 'No project selected — this tool is only available within a project that has uploaded documents.';
        }

        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Error: No search query provided.';
        }

        $limit = min(max((int) ($request['limit'] ?? 5), 1), 10);

        $hasChunks = DocumentChunk::where('project_id', $projectId)
            ->whereNotNull('embedding')
            ->exists();

        if (! $hasChunks) {
            return 'This project has no indexed documents yet. Upload documents to the project first.';
        }

        $chunks = DocumentChunk::query()
            ->where('project_id', $projectId)
            ->whereNotNull('embedding')
            ->whereVectorSimilarTo('embedding', $query, 0.5)
            ->limit($limit)
            ->get(['id', 'content', 'document_id', 'chunk_index']);

        if ($chunks->isEmpty()) {
            return "No relevant passages found in the project documents for: \"{$query}\"";
        }

        $chunks->load('document:id,name');

        return $chunks->map(function (DocumentChunk $chunk): string {
            /** @var Document|null $doc */
            $doc = $chunk->document;

            return sprintf(
                "[Source: %s, section %d]\n%s",
                $doc->name ?? 'Unknown',
                $chunk->chunk_index + 1,
                $chunk->content,
            );
        })->implode("\n\n---\n\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query describing what you are looking for in the project documents.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of relevant passages to return. Defaults to 5, max 10.')
                ->required()
                ->nullable(),
        ];
    }
}
