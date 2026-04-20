<?php

use App\Ai\Database\DatabaseConnectionStore;
use Illuminate\Support\Facades\Cache;

/*
 * The store is the seam where opaque credentials (never exposed to the
 * model) are exchanged for tokens (safe to echo). We care about three
 * invariants: round-tripping works, forget() really evicts, and a
 * tampered token returns null instead of partial/plaintext data.
 */

beforeEach(function () {
    Cache::flush();
    $this->store = new DatabaseConnectionStore(Cache::store());
});

test('stores credentials and resolves them by token', function () {
    $token = $this->store->store([
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'app',
    ], engine: 'mysql');

    expect($token)->toBeString()->not->toBeEmpty();

    $resolved = $this->store->resolve($token);

    expect($resolved)
        ->toHaveKey('engine', 'mysql')
        ->toHaveKey('credentials');

    expect($resolved['credentials'])->toMatchArray([
        'host' => '127.0.0.1',
        'username' => 'root',
        'database' => 'app',
    ]);
});

test('forget evicts the token so later resolves return null', function () {
    $token = $this->store->store(['database' => 'x'], engine: 'mysql');

    $this->store->forget($token);

    expect($this->store->resolve($token))->toBeNull();
});

test('resolve returns null for an unknown token', function () {
    expect($this->store->resolve('not-a-real-token'))->toBeNull();
});

test('resolve returns null when the ciphertext has been tampered with', function () {
    $token = $this->store->store(['database' => 'x'], engine: 'mysql');

    // Overwrite the cache entry with garbage that is not a valid
    // encrypted payload — should degrade gracefully.
    Cache::put('ai:db:'.$token, 'not-a-real-ciphertext', 60);

    expect($this->store->resolve($token))->toBeNull();
});

test('each call yields a unique token so two concurrent connections do not collide', function () {
    $a = $this->store->store(['database' => 'a'], engine: 'mysql');
    $b = $this->store->store(['database' => 'b'], engine: 'mysql');

    expect($a)->not->toBe($b);
    expect($this->store->resolve($a)['credentials']['database'])->toBe('a');
    expect($this->store->resolve($b)['credentials']['database'])->toBe('b');
});
