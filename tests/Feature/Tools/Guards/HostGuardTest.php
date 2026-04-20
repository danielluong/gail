<?php

use App\Ai\Tools\Guards\HostGuard;

test('allows URLs whose host is not in the deny list', function () {
    $guard = new HostGuard(deniedHosts: ['169.254.169.254']);

    expect($guard->allows('https://example.com/foo'))->toBeTrue();
    expect($guard->deniedHostFor('https://example.com/foo'))->toBeNull();
});

test('blocks URLs whose host exactly matches a denied host', function () {
    $guard = new HostGuard(deniedHosts: ['169.254.169.254']);

    expect($guard->allows('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
    expect($guard->deniedHostFor('http://169.254.169.254/latest/meta-data/'))
        ->toBe('169.254.169.254');
});

test('bare host patterns match exactly and do not block subdomains', function () {
    $guard = new HostGuard(deniedHosts: ['metadata.google.internal']);

    expect($guard->allows('http://metadata.google.internal/'))->toBeFalse();
    expect($guard->allows('http://evil.metadata.google.internal/'))->toBeTrue();
});

test('leading-wildcard patterns block every subdomain', function () {
    $guard = new HostGuard(deniedHosts: ['*.internal.corp']);

    expect($guard->allows('http://api.internal.corp/'))->toBeFalse();
    expect($guard->allows('http://deep.nested.internal.corp/'))->toBeFalse();
    expect($guard->allows('http://internal.corp/'))->toBeTrue();
    expect($guard->allows('http://example.com/'))->toBeTrue();
});

test('CIDR patterns block IPv4 addresses inside the range', function () {
    $guard = new HostGuard(deniedHosts: ['10.0.0.0/8']);

    expect($guard->allows('http://10.1.2.3/'))->toBeFalse();
    expect($guard->allows('http://10.255.255.255/'))->toBeFalse();
    expect($guard->allows('http://11.0.0.1/'))->toBeTrue();
    expect($guard->allows('http://example.com/'))->toBeTrue();
});

test('malformed URLs with no host are allowed (no host to match)', function () {
    $guard = new HostGuard(deniedHosts: ['169.254.169.254']);

    expect($guard->allows('not-a-url'))->toBeTrue();
});

test('an empty guard allows every URL', function () {
    $guard = new HostGuard;

    expect($guard->allows('http://169.254.169.254/'))->toBeTrue();
});

test('forTool merges the shared baseline with tool-specific extras', function () {
    config()->set('gail.tools.denied_hosts', ['shared.example']);
    config()->set('gail.tools.test_http.extra_denied_hosts', ['blocked.example']);

    $guard = HostGuard::forTool('test_http');

    expect($guard->allows('http://shared.example/'))->toBeFalse();
    expect($guard->allows('http://blocked.example/'))->toBeFalse();
    expect($guard->allows('http://allowed.example/'))->toBeTrue();
});

test('forTool applies the shared baseline to tools with no extras', function () {
    config()->set('gail.tools.denied_hosts', ['shared.example']);
    config()->set('gail.tools.no_extras', []);

    $guard = HostGuard::forTool('no_extras');

    expect($guard->allows('http://shared.example/'))->toBeFalse();
    expect($guard->allows('http://allowed.example/'))->toBeTrue();
});
