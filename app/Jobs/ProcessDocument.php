<?php

namespace App\Jobs;

use App\Ai\Support\TextChunker;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AttachmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Document $document,
    ) {}

    public function handle(TextChunker $chunker, AttachmentService $attachments): void
    {
        $this->document->update(['status' => DocumentStatus::Processing]);

        $text = $this->extractText($attachments);

        if ($text === null || trim($text) === '') {
            $this->document->update(['status' => DocumentStatus::Failed]);

            Log::channel('ai')->warning('ProcessDocument: no extractable text', [
                'document_id' => $this->document->id,
                'name' => $this->document->name,
            ]);

            return;
        }

        $chunks = $chunker->chunk($text);

        if ($chunks === []) {
            $this->document->update(['status' => DocumentStatus::Failed]);

            return;
        }

        $response = Embeddings::for($chunks)->generate('ollama');

        $rows = [];

        foreach ($chunks as $index => $content) {
            $rows[] = [
                'document_id' => $this->document->id,
                'project_id' => $this->document->project_id,
                'content' => $content,
                'embedding' => json_encode($response->embeddings[$index]),
                'chunk_index' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DocumentChunk::insert($rows);

        $this->document->update([
            'status' => DocumentStatus::Ready,
            'chunk_count' => count($chunks),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->document->update(['status' => DocumentStatus::Failed]);

        Log::channel('ai')->error('ProcessDocument failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
            'exception' => $e,
        ]);
    }

    private function extractText(AttachmentService $attachments): ?string
    {
        $path = storage_path('app/private/'.$this->document->disk_path);

        if (! file_exists($path)) {
            return null;
        }

        $mime = $this->document->mime_type ?? (mime_content_type($path) ?: '');

        if ($mime === 'application/pdf') {
            return $attachments->extractPdfText($path);
        }

        return file_get_contents($path) ?: null;
    }
}
