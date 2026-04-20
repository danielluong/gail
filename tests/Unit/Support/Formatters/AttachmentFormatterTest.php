<?php

use App\Support\Formatters\AttachmentFormatter;

test('returns empty array when attachments are null or empty', function () {
    expect((new AttachmentFormatter)->format(null))->toBe([]);
    expect((new AttachmentFormatter)->format([]))->toBe([]);
});

test('derives name and url from path when name is missing', function () {
    $formatter = new AttachmentFormatter;

    $result = $formatter->format([
        ['path' => '/var/uploads/private/report.pdf', 'mime' => 'application/pdf'],
    ]);

    expect($result[0]['name'])->toBe('report.pdf');
    expect($result[0]['type'])->toBe('application/pdf');
    expect($result[0]['url'])->toContain('report.pdf');
});

test('falls back gracefully when path is missing', function () {
    $result = (new AttachmentFormatter)->format([
        ['name' => 'untitled'],
    ]);

    expect($result[0])->toBe([
        'name' => 'untitled',
        'type' => null,
        'url' => null,
    ]);
});
