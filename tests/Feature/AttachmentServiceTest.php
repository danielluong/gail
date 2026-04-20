<?php

use App\Services\AttachmentService;
use Laravel\Ai\Files\LocalImage;

test('prepare wraps images as Image attachments and leaves the message untouched', function () {
    $image = tempnam(sys_get_temp_dir(), 'gail-test').'.png';
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUeJxjYgAAAAYAAzY3fKgAAAAASUVORK5CYII='));

    try {
        $result = (new AttachmentService)->prepare([$image], 'What is this?');
    } finally {
        @unlink($image);
    }

    expect($result['message'])->toBe('What is this?')
        ->and($result['attachments'])->toHaveCount(1)
        ->and($result['attachments'][0])->toBeInstanceOf(LocalImage::class)
        ->and($result['warnings'])->toBe([]);
});

test('prepare inlines text file contents into the message and sends no attachments', function () {
    $textFile = tempnam(sys_get_temp_dir(), 'gail-test').'.txt';
    file_put_contents($textFile, 'hello from the test');

    try {
        $result = (new AttachmentService)->prepare([$textFile], 'Summarize this');
    } finally {
        @unlink($textFile);
    }

    expect($result['attachments'])->toBe([])
        ->and($result['message'])->toContain('hello from the test')
        ->and($result['message'])->toContain('Summarize this')
        ->and($result['warnings'])->toBe([]);
});

test('prepare silently skips missing paths', function () {
    $result = (new AttachmentService)->prepare(['/nonexistent/path/xyz.png'], 'Hi');

    expect($result['attachments'])->toBe([])
        ->and($result['message'])->toBe('Hi')
        ->and($result['warnings'])->toBe([]);
});

test('prepare emits a warning when a text attachment is truncated', function () {
    config()->set('gail.tools.max_output_bytes.attachment_text', 32);

    $textFile = tempnam(sys_get_temp_dir(), 'gail-test').'.txt';
    file_put_contents($textFile, str_repeat('a', 200));

    try {
        $result = (new AttachmentService)->prepare([$textFile], 'Summarize');
    } finally {
        @unlink($textFile);
    }

    expect($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain(basename($textFile))
        ->and($result['warnings'][0])->toContain('truncated')
        ->and($result['message'])->toContain('[Truncated]');
});

test('prepare extracts PDF text via pdftotext when the binary is available', function () {
    $pdftotext = collect(['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext'])
        ->first(fn (string $bin) => is_file($bin));

    if ($pdftotext === null) {
        $this->markTestSkipped('pdftotext is not installed in this environment');
    }

    // Build a tiny valid PDF with the string "gail-test-pdf" using an
    // inline helper — avoids committing a binary fixture to the repo.
    $pdf = buildMinimalPdf('gail-test-pdf');
    $path = tempnam(sys_get_temp_dir(), 'gail-test').'.pdf';
    file_put_contents($path, $pdf);

    try {
        $result = (new AttachmentService)->prepare([$path], 'Summarize');
    } finally {
        @unlink($path);
    }

    expect($result['attachments'])->toBe([])
        ->and($result['message'])->toContain('gail-test-pdf')
        ->and($result['message'])->toContain('Summarize')
        ->and($result['warnings'])->toBe([]);
});

function buildMinimalPdf(string $text): string
{
    $escaped = addslashes($text);
    $objects = [
        '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
        '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
        '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj',
        "4 0 obj<</Length 55>>stream\nBT /F1 24 Tf 20 100 Td ({$escaped}) Tj ET\nendstream\nendobj",
        '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj."\n";
    }

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer<</Size '.(count($objects) + 1)."/Root 1 0 R>>\nstartxref\n{$xrefStart}\n%%EOF";

    return $pdf;
}
