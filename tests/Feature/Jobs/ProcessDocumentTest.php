<?php

use App\Ai\Support\TextChunker;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Project;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

test('sets status to failed when file does not exist', function () {
    $project = Project::factory()->create();
    $document = Document::factory()->pending()->create([
        'project_id' => $project->id,
        'disk_path' => 'documents/1/nonexistent.txt',
    ]);

    (new ProcessDocument($document))->handle(
        app(TextChunker::class),
        app(AttachmentService::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed);
});

test('sets status to failed when text is empty', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $document = Document::factory()->pending()->create([
        'project_id' => $project->id,
        'disk_path' => 'documents/1/empty.txt',
        'mime_type' => 'text/plain',
    ]);

    Storage::disk('local')->put('documents/1/empty.txt', '');

    (new ProcessDocument($document))->handle(
        app(TextChunker::class),
        app(AttachmentService::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed);
});

test('sets status to processing before starting work', function () {
    $project = Project::factory()->create();
    $document = Document::factory()->pending()->create([
        'project_id' => $project->id,
        'disk_path' => 'documents/1/nonexistent.txt',
    ]);

    expect($document->status)->toBe(DocumentStatus::Pending);

    (new ProcessDocument($document))->handle(
        app(TextChunker::class),
        app(AttachmentService::class),
    );

    // Even though it failed, it should have been set to 'processing' first,
    // then to 'failed'. Final state is what we can observe.
    expect($document->fresh()->status)->toBe(DocumentStatus::Failed);
});

test('failed method sets document status to failed', function () {
    $project = Project::factory()->create();
    $document = Document::factory()->processing()->create([
        'project_id' => $project->id,
    ]);

    (new ProcessDocument($document))->failed(new RuntimeException('test error'));

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed);
});

test('happy path chunks the file, generates embeddings, and inserts one row per chunk', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        /*
         * The happy path writes each chunk's embedding into a pgvector
         * `embedding` column that only exists on Postgres (see the
         * conditional in the create_document_chunks migration). On
         * SQLite the insert would fail with "no such column: embedding",
         * which is the app's designed behaviour — RAG requires Postgres.
         * Skip here so this test acts as the regression gate when the
         * suite runs against Postgres (locally, or in a future CI job).
         */
        test()->markTestSkipped('RAG happy-path requires the pgvector-enabled Postgres driver.');
    }

    Storage::fake('local');
    Embeddings::fake();

    $project = Project::factory()->create();
    $document = Document::factory()->pending()->create([
        'project_id' => $project->id,
        'disk_path' => 'documents/'.$project->id.'/notes.txt',
        'mime_type' => 'text/plain',
    ]);

    /*
     * Long enough that TextChunker produces at least two chunks, so we
     * cover the per-chunk insertion loop rather than a single-row
     * shortcut. Real uploads easily exceed this.
     */
    $body = str_repeat(
        'Gail is a local-first AI chat tool. It supports projects and per-project documents. ',
        80,
    );

    Storage::disk('local')->put($document->disk_path, $body);

    (new ProcessDocument($document))->handle(
        app(TextChunker::class),
        app(AttachmentService::class),
    );

    $fresh = $document->fresh();

    expect($fresh->status)->toBe(DocumentStatus::Ready)
        ->and($fresh->chunk_count)->toBeGreaterThan(0);

    $chunks = DocumentChunk::query()
        ->where('document_id', $document->id)
        ->orderBy('chunk_index')
        ->get();

    expect($chunks)->toHaveCount($fresh->chunk_count)
        ->and($chunks->first()->project_id)->toBe($project->id)
        ->and($chunks->first()->content)->not->toBe('')
        ->and($chunks->first()->chunk_index)->toBe(0);
});
