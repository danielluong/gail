<?php

use App\Ai\Tools\Chat\CurrentLocation;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

test('returns formatted location for the current ip', function () {
    Http::fake([
        'ipapi.co/json/*' => Http::response([
            'ip' => '203.0.113.5',
            'city' => 'Brooklyn',
            'region' => 'New York',
            'country_name' => 'United States',
            'postal' => '11201',
            'latitude' => 40.6943,
            'longitude' => -73.9903,
            'timezone' => 'America/New_York',
            'utc_offset' => '-0400',
        ], 200),
    ]);

    $result = (string) (new CurrentLocation)->handle(new Request([]));

    expect($result)
        ->toContain('Location: Brooklyn, New York, United States')
        ->toContain('Postal code: 11201')
        ->toContain('Coordinates: 40.6943, -73.9903')
        ->toContain('Timezone: America/New_York (UTC -0400)')
        ->toContain('Resolved from IP: 203.0.113.5');
});

test('looks up an explicit ip when provided', function () {
    Http::fake([
        'ipapi.co/8.8.8.8/json/*' => Http::response([
            'ip' => '8.8.8.8',
            'city' => 'Mountain View',
            'region' => 'California',
            'country_name' => 'United States',
            'latitude' => 37.386,
            'longitude' => -122.0838,
            'timezone' => 'America/Los_Angeles',
            'utc_offset' => '-0700',
        ], 200),
    ]);

    $result = (string) (new CurrentLocation)->handle(new Request([
        'ip' => '8.8.8.8',
    ]));

    expect($result)
        ->toContain('Location: Mountain View, California, United States')
        ->toContain('Resolved from IP: 8.8.8.8');
});

test('surfaces ipapi error responses', function () {
    Http::fake([
        'ipapi.co/*' => Http::response([
            'error' => true,
            'reason' => 'RateLimited',
            'message' => 'Too many requests',
        ], 200),
    ]);

    $result = (string) (new CurrentLocation)->handle(new Request([]));

    expect($result)->toContain('Error: Location lookup failed — RateLimited');
});

test('returns error for non-success http statuses', function () {
    Http::fake([
        'ipapi.co/*' => Http::response('oops', 503),
    ]);

    $result = (string) (new CurrentLocation)->handle(new Request([]));

    expect($result)->toContain('Error: Location lookup returned HTTP 503');
});

test('respects the host denylist', function () {
    $guard = new HostGuard(['ipapi.co']);

    $result = (string) (new CurrentLocation($guard))->handle(new Request([]));

    expect($result)->toContain("Error: Requests to 'ipapi.co' are blocked");
});
