<?php

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('upload creates a document record and dispatches the processing job', function () {
    Queue::fake();
    Storage::fake('local');

    $project = Project::factory()->create();

    $file = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');

    $this->postJson(route('documents.store', $project->id), ['file' => $file])
        ->assertCreated()
        ->assertJsonStructure(['id', 'name', 'status', 'size'])
        ->assertJsonFragment(['name' => 'notes.txt', 'status' => 'pending']);

    expect(Document::where('project_id', $project->id)->count())->toBe(1);

    Queue::assertPushed(ProcessDocument::class, function ($job) {
        return $job->document->name === 'notes.txt';
    });
});

test('upload rejects files exceeding 20MB', function () {
    $project = Project::factory()->create();

    $file = UploadedFile::fake()->create('huge.txt', 21000, 'text/plain');

    $this->postJson(route('documents.store', $project->id), ['file' => $file])
        ->assertUnprocessable();
});

test('upload rejects unsupported mime types', function () {
    $project = Project::factory()->create();

    $file = UploadedFile::fake()->create('image.jpg', 100, 'image/jpeg');

    $this->postJson(route('documents.store', $project->id), ['file' => $file])
        ->assertUnprocessable();
});

test('index lists documents for a project', function () {
    $project = Project::factory()->create();

    Document::factory()->count(3)->create(['project_id' => $project->id]);

    $this->getJson(route('documents.index', $project->id))
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure([['id', 'name', 'status', 'chunk_count', 'size']]);
});

test('index does not leak documents from other projects', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    Document::factory()->create(['project_id' => $other->id]);

    $this->getJson(route('documents.index', $project->id))
        ->assertOk()
        ->assertJsonCount(0);
});

test('destroy deletes the document and its file', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $document = Document::factory()->create([
        'project_id' => $project->id,
        'disk_path' => 'documents/1/test.txt',
    ]);

    Storage::disk('local')->put('documents/1/test.txt', 'content');

    $this->deleteJson(route('documents.destroy', [$project->id, $document->id]))
        ->assertNoContent();

    expect(Document::find($document->id))->toBeNull();
    Storage::disk('local')->assertMissing('documents/1/test.txt');
});

test('destroy returns 404 when document belongs to another project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $document = Document::factory()->create(['project_id' => $other->id]);

    $this->deleteJson(route('documents.destroy', [$project->id, $document->id]))
        ->assertNotFound();
});
