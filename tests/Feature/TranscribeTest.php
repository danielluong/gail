<?php

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Transcription;

beforeEach(function () {
    config()->set('ai.default_for_transcription', 'openai');
});

test('transcribe returns text from audio upload', function () {
    Transcription::fake([
        fn () => 'hello from whisper',
    ]);

    $file = UploadedFile::fake()->create('recording.webm', 50, 'audio/webm');

    $this->postJson(route('chat.transcribe'), ['audio' => $file])
        ->assertOk()
        ->assertJson(['text' => 'hello from whisper']);
});

test('transcribe requires an audio file', function () {
    $this->postJson(route('chat.transcribe'), [])
        ->assertUnprocessable();
});
