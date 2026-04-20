<?php

use App\Ai\Tools\Chat\CurrentDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('returns current date and time information', function () {
    $result = (string) (new CurrentDateTime)->handle(new Request([]));

    expect($result)
        ->toContain('Current date and time:')
        ->toContain('Day:')
        ->toContain('Time of day:')
        ->toContain('Timezone:')
        ->toContain('UTC offset:')
        ->toContain('ISO 8601:')
        ->toContain('Unix timestamp:');
});

test('accepts a custom timezone', function () {
    $result = (string) (new CurrentDateTime)->handle(new Request(['timezone' => 'Asia/Tokyo']));

    expect($result)->toContain('Timezone: Asia/Tokyo');
});

test('returns error for invalid timezone', function () {
    $result = (string) (new CurrentDateTime)->handle(new Request(['timezone' => 'Invalid/Zone']));

    expect($result)->toContain('Invalid timezone');
});

test('returns the day-of-week with a weekend flag', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-11 19:30:00', 'UTC'));

    $result = (string) (new CurrentDateTime)->handle(new Request(['timezone' => 'UTC']));

    expect($result)->toContain('Day: Saturday (weekend)');
});

test('returns a clock-based time-of-day bucket', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-11 19:30:00', 'UTC'));

    $result = (string) (new CurrentDateTime)->handle(new Request(['timezone' => 'UTC']));

    expect($result)
        ->toContain('Time of day: evening')
        ->not->toContain('dinner')
        ->not->toContain('business');
});

test('does not add a weekend flag on weekdays', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-08 09:00:00', 'UTC'));

    $result = (string) (new CurrentDateTime)->handle(new Request(['timezone' => 'UTC']));

    expect($result)
        ->toContain('Day: Wednesday')
        ->not->toContain('Day: Wednesday (weekend)');
});

test('accepts a location and renders time in that city\'s timezone', function () {
    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response([
            'results' => [[
                'name' => 'Tokyo',
                'timezone' => 'Asia/Tokyo',
            ]],
        ], 200),
    ]);

    $result = (string) (new CurrentDateTime)->handle(new Request(['location' => 'Tokyo']));

    expect($result)->toContain('Timezone: Asia/Tokyo');
});

test('falls back gracefully when geocoding has no match', function () {
    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response(['results' => []], 200),
    ]);

    $result = (string) (new CurrentDateTime)->handle(new Request(['location' => 'Nowhereville']));

    expect($result)
        ->toContain('Current date and time:')
        ->toContain('Could not resolve "Nowhereville"; using default timezone.');
});

test('prefers explicit timezone over location', function () {
    Http::fake();

    $result = (string) (new CurrentDateTime)->handle(new Request([
        'timezone' => 'Europe/London',
        'location' => 'Tokyo',
    ]));

    expect($result)->toContain('Timezone: Europe/London');

    Http::assertNothingSent();
});
