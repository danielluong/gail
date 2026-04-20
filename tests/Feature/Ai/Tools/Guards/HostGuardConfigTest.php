<?php

use App\Ai\Tools\Guards\HostGuard;

test('cloud metadata hosts are blocked for every tool by the shared baseline', function (string $tool) {
    $guard = HostGuard::forTool($tool);

    expect($guard->allows('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
    expect($guard->allows('http://metadata.google.internal/'))->toBeFalse();
})->with([
    'web_fetch',
    'web_search',
    'wikipedia',
    'weather',
    'current_datetime',
    'current_location',
]);

test('web_fetch extends the baseline with loopback denial for user-supplied URLs', function () {
    $guard = HostGuard::forTool('web_fetch');

    expect($guard->allows('http://localhost/'))->toBeFalse();
    expect($guard->allows('http://127.0.0.1/'))->toBeFalse();
    expect($guard->allows('http://169.254.169.254/'))->toBeFalse();
});

test('tools without loopback extras still allow localhost', function () {
    $guard = HostGuard::forTool('web_search');

    expect($guard->allows('http://localhost/'))->toBeTrue();
});
