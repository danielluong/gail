<?php

test('loopback requests are allowed through', function () {
    $this->get(route('home'), ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertOk();
});

test('IPv6 loopback requests are allowed through', function () {
    $this->call('GET', route('home'), server: ['REMOTE_ADDR' => '::1'])
        ->assertOk();
});

test('non-loopback requests are rejected with 403 by default', function () {
    $this->call('GET', route('home'), server: ['REMOTE_ADDR' => '203.0.113.10'])
        ->assertForbidden();
});

test('non-loopback requests are allowed when gail.allow_remote is true', function () {
    config()->set('gail.allow_remote', true);

    $this->call('GET', route('home'), server: ['REMOTE_ADDR' => '203.0.113.10'])
        ->assertOk();
});

test('private network requests are rejected by default', function () {
    $this->call('GET', route('home'), server: ['REMOTE_ADDR' => '192.168.1.50'])
        ->assertForbidden();
});

test('X-Forwarded-For is ignored: the raw remote address governs access', function () {
    $this->call('GET', route('home'), server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
    ])->assertForbidden();

    $this->call('GET', route('home'), server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
    ])->assertOk();
});
