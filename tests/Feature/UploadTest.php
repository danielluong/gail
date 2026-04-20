<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('upload stores file and returns metadata', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

    $response = $this->postJson(route('chat.upload'), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['name', 'path', 'url', 'size', 'type'])
        ->assertJsonFragment(['name' => 'document.txt']);

    expect($response->json('url'))->toContain('/uploads/');
});

test('upload rejects files larger than 10MB', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->create('large.pdf', 11_000, 'application/pdf');

    $this->postJson(route('chat.upload'), ['file' => $file])
        ->assertUnprocessable();
});

test('upload requires a file', function () {
    $this->postJson(route('chat.upload'), [])
        ->assertUnprocessable();
});

test('upload handles image files', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->image('photo.jpg', 640, 480);

    $this->postJson(route('chat.upload'), ['file' => $file])
        ->assertOk()
        ->assertJsonFragment(['name' => 'photo.jpg']);
});

test('uploads.show serves a previously uploaded file', function () {
    $dir = storage_path('app/private/uploads');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'gail-upload-test.txt';
    $path = $dir.'/'.$filename;
    file_put_contents($path, 'hello world');

    try {
        $response = $this->get(route('uploads.show', ['filename' => $filename]))
            ->assertOk();

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        expect($body)->toBe('hello world');
    } finally {
        @unlink($path);
    }
});

test('uploads.show returns 404 for a missing file', function () {
    $this->get(route('uploads.show', ['filename' => 'does-not-exist.png']))
        ->assertNotFound();
});

test('uploads.show rejects path traversal attempts', function () {
    // The route regex constraint forbids slashes and dots-only segments,
    // so traversal paths shouldn't even resolve the route.
    $this->get('/uploads/..%2Fsecrets.txt')
        ->assertNotFound();
});
