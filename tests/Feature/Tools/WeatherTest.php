<?php

use App\Ai\Tools\Chat\Weather;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function fakeForecastPayload(): array
{
    return [
        'timezone' => 'America/New_York',
        'current' => [
            'temperature_2m' => 18.3,
            'apparent_temperature' => 17.1,
            'relative_humidity_2m' => 62,
            'precipitation' => 0.0,
            'weather_code' => 3,
            'wind_speed_10m' => 12.4,
        ],
        'daily' => [
            'time' => ['2026-04-11', '2026-04-12', '2026-04-13'],
            'weather_code' => [3, 61, 0],
            'temperature_2m_max' => [19.5, 17.2, 20.1],
            'temperature_2m_min' => [11.0, 10.5, 12.0],
            'precipitation_probability_max' => [10, 70, 0],
            'sunrise' => ['2026-04-11T06:20', '2026-04-12T06:19', '2026-04-13T06:17'],
            'sunset' => ['2026-04-11T19:30', '2026-04-12T19:31', '2026-04-13T19:32'],
        ],
    ];
}

test('returns error when neither coordinates nor location are provided', function () {
    $result = (string) (new Weather)->handle(new Request([]));

    expect($result)->toContain('Error: Provide either latitude and longitude');
});

test('returns error for invalid units', function () {
    $result = (string) (new Weather)->handle(new Request([
        'latitude' => 40.71,
        'longitude' => -74.0,
        'units' => 'kelvin',
    ]));

    expect($result)->toContain("Error: units must be 'metric' or 'imperial'");
});

test('formats a forecast for explicit coordinates', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response(fakeForecastPayload(), 200),
    ]);

    $result = (string) (new Weather)->handle(new Request([
        'latitude' => 40.71,
        'longitude' => -74.0,
    ]));

    expect($result)
        ->toContain('Weather for lat 40.71, lon -74')
        ->toContain('Timezone: America/New_York')
        ->toContain('Now: Overcast')
        ->toContain('Temperature: 18.3°C (feels like 17.1°C)')
        ->toContain('Humidity: 62%')
        ->toContain('Wind: 12.4 km/h')
        ->toContain('Forecast:')
        ->toContain('Today (2026-04-11): Overcast — high 19.5°C, low 11°C — precip 10%')
        ->toContain('Tomorrow (2026-04-12): Slight rain — high 17.2°C, low 10.5°C — precip 70%');
});

test('uses imperial units when requested', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'timezone' => 'America/Los_Angeles',
            'current' => [
                'temperature_2m' => 72,
                'apparent_temperature' => 71,
                'relative_humidity_2m' => 50,
                'precipitation' => 0,
                'weather_code' => 0,
                'wind_speed_10m' => 5,
            ],
            'daily' => [
                'time' => ['2026-04-11'],
                'weather_code' => [0],
                'temperature_2m_max' => [75],
                'temperature_2m_min' => [55],
                'precipitation_probability_max' => [0],
                'sunrise' => ['2026-04-11T06:20'],
                'sunset' => ['2026-04-11T19:30'],
            ],
        ], 200),
    ]);

    $result = (string) (new Weather)->handle(new Request([
        'latitude' => 34.05,
        'longitude' => -118.24,
        'units' => 'imperial',
    ]));

    expect($result)
        ->toContain('Temperature: 72°F')
        ->toContain('Wind: 5 mph')
        ->toContain('Today (2026-04-11): Clear sky — high 75°F, low 55°F');
});

test('geocodes a location name before fetching the forecast', function () {
    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response([
            'results' => [[
                'name' => 'Brooklyn',
                'admin1' => 'New York',
                'country' => 'United States',
                'latitude' => 40.6943,
                'longitude' => -73.9903,
            ]],
        ], 200),
        'api.open-meteo.com/*' => Http::response(fakeForecastPayload(), 200),
    ]);

    $result = (string) (new Weather)->handle(new Request([
        'location' => 'Brooklyn',
    ]));

    expect($result)
        ->toContain('Weather for Brooklyn, New York, United States')
        ->toContain('lat 40.6943, lon -73.9903');
});

test('returns a friendly error when geocoding finds no match', function () {
    Http::fake([
        'geocoding-api.open-meteo.com/*' => Http::response(['results' => []], 200),
    ]);

    $result = (string) (new Weather)->handle(new Request([
        'location' => 'Nowhereville',
    ]));

    expect($result)->toContain('Error: Could not find a location matching "Nowhereville"');
});

test('returns error for non-success forecast responses', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response('oops', 503),
    ]);

    $result = (string) (new Weather)->handle(new Request([
        'latitude' => 40.71,
        'longitude' => -74.0,
    ]));

    expect($result)->toContain('Error: Forecast lookup returned HTTP 503');
});

test('respects the host denylist', function () {
    $guard = new HostGuard(['api.open-meteo.com']);

    $result = (string) (new Weather($guard))->handle(new Request([
        'latitude' => 40.71,
        'longitude' => -74.0,
    ]));

    expect($result)->toContain("Error: Requests to 'api.open-meteo.com' are blocked");
});
