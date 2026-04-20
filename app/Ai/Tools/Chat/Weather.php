<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class Weather implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 10;

    protected const FORECAST_ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    protected const GEOCODING_ENDPOINT = 'https://geocoding-api.open-meteo.com/v1/search';

    protected const FORECAST_DAYS = 3;

    /**
     * WMO weather interpretation codes.
     *
     * @see https://open-meteo.com/en/docs
     *
     * @var array<int, string>
     */
    protected const WEATHER_CODES = [
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Depositing rime fog',
        51 => 'Light drizzle',
        53 => 'Moderate drizzle',
        55 => 'Dense drizzle',
        56 => 'Light freezing drizzle',
        57 => 'Dense freezing drizzle',
        61 => 'Slight rain',
        63 => 'Moderate rain',
        65 => 'Heavy rain',
        66 => 'Light freezing rain',
        67 => 'Heavy freezing rain',
        71 => 'Slight snow',
        73 => 'Moderate snow',
        75 => 'Heavy snow',
        77 => 'Snow grains',
        80 => 'Slight rain showers',
        81 => 'Moderate rain showers',
        82 => 'Violent rain showers',
        85 => 'Slight snow showers',
        86 => 'Heavy snow showers',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with slight hail',
        99 => 'Thunderstorm with heavy hail',
    ];

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
    ) {}

    public function label(): string
    {
        return 'Checked the weather';
    }

    public function description(): Stringable|string
    {
        return 'Get the current weather and a 3-day forecast for a location. Accepts either a place name (e.g. "Brooklyn, NY") or explicit latitude/longitude coordinates. Use this for "will it rain tonight?", "how cold is it?", or any weather-sensitive suggestion.';
    }

    public function handle(Request $request): Stringable|string
    {
        $latitude = $this->toFloat($request['latitude'] ?? null);
        $longitude = $this->toFloat($request['longitude'] ?? null);
        $location = trim((string) ($request['location'] ?? ''));
        $units = strtolower(trim((string) ($request['units'] ?? 'metric')));

        if (! in_array($units, ['metric', 'imperial'], true)) {
            return "Error: units must be 'metric' or 'imperial'.";
        }

        $resolvedName = null;

        if ($latitude === null || $longitude === null) {
            if ($location === '') {
                return 'Error: Provide either latitude and longitude, or a location name.';
            }

            $geocoded = $this->geocode($location);

            if (is_string($geocoded)) {
                return $geocoded;
            }

            [$latitude, $longitude, $resolvedName] = $geocoded;
        }

        $forecast = $this->fetchForecast($latitude, $longitude, $units);

        if (is_string($forecast)) {
            return $forecast;
        }

        return $this->format($forecast, $units, $resolvedName, $latitude, $longitude);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()
                ->description('A place name like "Brooklyn, NY" or "Tokyo". Ignored when latitude and longitude are provided.')
                ->required()
                ->nullable(),
            'latitude' => $schema->number()
                ->description('Latitude in decimal degrees. Must be paired with longitude.')
                ->required()
                ->nullable(),
            'longitude' => $schema->number()
                ->description('Longitude in decimal degrees. Must be paired with latitude.')
                ->required()
                ->nullable(),
            'units' => $schema->string()
                ->description("'metric' (°C, km/h, mm) or 'imperial' (°F, mph, inch). Defaults to metric.")
                ->enum(['metric', 'imperial', null])
                ->required()
                ->nullable(),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: string}|string
     */
    private function geocode(string $location): array|string
    {
        if ($blocked = $this->guard()->deniedHostFor(self::GEOCODING_ENDPOINT)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::GEOCODING_ENDPOINT, [
                    'name' => $location,
                    'count' => 1,
                    'format' => 'json',
                ]);
        } catch (Throwable $e) {
            return "Error: Geocoding failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: Geocoding returned HTTP {$response->status()}.";
        }

        $data = $response->json();
        $result = $data['results'][0] ?? null;

        if (! is_array($result) || ! isset($result['latitude'], $result['longitude'])) {
            return "Error: Could not find a location matching \"{$location}\".";
        }

        $name = trim(implode(', ', array_filter([
            (string) ($result['name'] ?? ''),
            (string) ($result['admin1'] ?? ''),
            (string) ($result['country'] ?? ''),
        ], fn (string $part) => $part !== '')));

        return [(float) $result['latitude'], (float) $result['longitude'], $name];
    }

    /**
     * @return array<string, mixed>|string
     */
    private function fetchForecast(float $latitude, float $longitude, string $units): array|string
    {
        if ($blocked = $this->guard()->deniedHostFor(self::FORECAST_ENDPOINT)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        $imperial = $units === 'imperial';

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::FORECAST_ENDPOINT, [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
                    'temperature_unit' => $imperial ? 'fahrenheit' : 'celsius',
                    'wind_speed_unit' => $imperial ? 'mph' : 'kmh',
                    'precipitation_unit' => $imperial ? 'inch' : 'mm',
                    'timezone' => 'auto',
                    'forecast_days' => self::FORECAST_DAYS,
                ]);
        } catch (Throwable $e) {
            return "Error: Forecast lookup failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: Forecast lookup returned HTTP {$response->status()}.";
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['current'], $data['daily'])) {
            return 'Error: Forecast response was malformed.';
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $forecast
     */
    private function format(array $forecast, string $units, ?string $resolvedName, float $latitude, float $longitude): string
    {
        $labels = $this->unitLabels($units);
        $timezone = (string) ($forecast['timezone'] ?? 'UTC');

        $lines = [
            $this->header($resolvedName, $latitude, $longitude),
            "Timezone: {$timezone}",
            '',
            ...$this->formatCurrent(is_array($forecast['current'] ?? null) ? $forecast['current'] : [], $labels),
            ...$this->formatForecast(is_array($forecast['daily'] ?? null) ? $forecast['daily'] : [], $labels),
        ];

        return rtrim(implode("\n", $lines));
    }

    /**
     * @return array{temp: string, wind: string, precip: string}
     */
    private function unitLabels(string $units): array
    {
        return $units === 'imperial'
            ? ['temp' => '°F', 'wind' => 'mph', 'precip' => 'in']
            : ['temp' => '°C', 'wind' => 'km/h', 'precip' => 'mm'];
    }

    private function header(?string $resolvedName, float $latitude, float $longitude): string
    {
        return $resolvedName !== null
            ? "Weather for {$resolvedName} (lat {$latitude}, lon {$longitude})"
            : "Weather for lat {$latitude}, lon {$longitude}";
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array{temp: string, wind: string, precip: string}  $labels
     * @return list<string>
     */
    private function formatCurrent(array $current, array $labels): array
    {
        $lines = ['Now: '.$this->describeCode((int) ($current['weather_code'] ?? -1))];

        $temperature = $current['temperature_2m'] ?? null;
        $apparent = $current['apparent_temperature'] ?? null;

        if (is_numeric($temperature)) {
            $feels = is_numeric($apparent) ? " (feels like {$apparent}{$labels['temp']})" : '';
            $lines[] = "Temperature: {$temperature}{$labels['temp']}{$feels}";
        }

        if (is_numeric($current['relative_humidity_2m'] ?? null)) {
            $lines[] = "Humidity: {$current['relative_humidity_2m']}%";
        }

        if (is_numeric($current['wind_speed_10m'] ?? null)) {
            $lines[] = "Wind: {$current['wind_speed_10m']} {$labels['wind']}";
        }

        if (is_numeric($current['precipitation'] ?? null)) {
            $lines[] = "Precipitation: {$current['precipitation']} {$labels['precip']}";
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $daily
     * @param  array{temp: string, wind: string, precip: string}  $labels
     * @return list<string>
     */
    private function formatForecast(array $daily, array $labels): array
    {
        $dates = $daily['time'] ?? [];

        if (! is_array($dates) || $dates === []) {
            return [];
        }

        $dailyCodes = $daily['weather_code'] ?? [];
        $maxTemps = $daily['temperature_2m_max'] ?? [];
        $minTemps = $daily['temperature_2m_min'] ?? [];
        $precipProb = $daily['precipitation_probability_max'] ?? [];

        $lines = ['', 'Forecast:'];

        foreach ($dates as $index => $date) {
            $label = $this->dayLabel($index, (string) $date);
            $high = $maxTemps[$index] ?? null;
            $low = $minTemps[$index] ?? null;
            $prob = $precipProb[$index] ?? null;

            $parts = ["  {$label}: ".$this->describeCode((int) ($dailyCodes[$index] ?? -1))];

            if (is_numeric($high) && is_numeric($low)) {
                $parts[] = "high {$high}{$labels['temp']}, low {$low}{$labels['temp']}";
            }

            if (is_numeric($prob)) {
                $parts[] = "precip {$prob}%";
            }

            $lines[] = implode(' — ', $parts);
        }

        return $lines;
    }

    private function describeCode(int $code): string
    {
        return self::WEATHER_CODES[$code] ?? "Weather code {$code}";
    }

    private function dayLabel(int $index, string $date): string
    {
        return match ($index) {
            0 => "Today ({$date})",
            1 => "Tomorrow ({$date})",
            default => $date,
        };
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('weather');
    }
}
