<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Tools\Guards\HostGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class CurrentDateTime implements DisplayableTool, Tool
{
    protected const GEOCODING_ENDPOINT = 'https://geocoding-api.open-meteo.com/v1/search';

    protected const GEOCODING_TIMEOUT_SECONDS = 5;

    protected const GEOCODING_CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
    ) {}

    public function label(): string
    {
        return 'Checked the time';
    }

    public function description(): Stringable|string
    {
        return 'Get the current local date, time, day-of-week (with weekend flag), and time-of-day bucket. Use this whenever the user mentions "now", "tonight", "today", "open", "near me", or a specific city. For location-aware queries, either (a) pass a "location" string like "Brooklyn, NY" so the time is rendered in that city\'s timezone, or (b) chain with CurrentLocation first and pass its Timezone value as the "timezone" arg. When answering time-sensitive recommendations, include the day name and time-of-day in your follow-up WebSearch query so results reflect places open at the actual current time.';
    }

    public function handle(Request $request): Stringable|string
    {
        $timezone = trim((string) ($request['timezone'] ?? ''));
        $location = trim((string) ($request['location'] ?? ''));

        $locationNote = null;

        if ($timezone === '' && $location !== '') {
            $resolved = $this->resolveTimezoneFromLocation($location);

            if ($resolved !== null) {
                $timezone = $resolved;
            } else {
                $locationNote = "Note: Could not resolve \"{$location}\"; using default timezone.";
            }
        }

        if ($timezone === '') {
            $timezone = (string) config('app.timezone');
        }

        try {
            $now = CarbonImmutable::now($timezone);
        } catch (Throwable) {
            return "Invalid timezone: {$timezone}. Use a valid timezone like 'America/New_York' or 'Asia/Tokyo'.";
        }

        $lines = [
            "Current date and time: {$now->format('Y-m-d H:i:s')}",
            'Day: '.$this->dayLabel($now),
            'Time of day: '.$this->timeOfDay((int) $now->format('G')),
            "Timezone: {$now->timezoneName}",
            "UTC offset: {$now->format('P')}",
            "ISO 8601: {$now->toIso8601String()}",
            "Unix timestamp: {$now->timestamp}",
        ];

        if ($locationNote !== null) {
            $lines[] = '';
            $lines[] = $locationNote;
        }

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema->string()
                ->description("Timezone name (e.g. 'America/New_York', 'Asia/Tokyo'). Defaults to the app's configured timezone. Takes precedence over 'location' when both are provided.")
                ->required()
                ->nullable(),
            'location' => $schema->string()
                ->description('A place name like "Brooklyn, NY" or "Tokyo" to render time in that city\'s local timezone. Ignored when "timezone" is set.')
                ->required()
                ->nullable(),
        ];
    }

    private function resolveTimezoneFromLocation(string $location): ?string
    {
        if ($this->guard()->deniedHostFor(self::GEOCODING_ENDPOINT) !== null) {
            return null;
        }

        // Cache the normalized location → timezone result for a day so
        // repeated "near me" chats from the same place avoid round-trips
        // to the Open-Meteo geocoder.
        $cacheKey = 'gail:current_datetime:tz:'.strtolower($location);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout(self::GEOCODING_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::GEOCODING_ENDPOINT, [
                    'name' => $location,
                    'count' => 1,
                    'format' => 'json',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $timezone = $response->json('results.0.timezone');

        if (is_string($timezone) && $timezone !== '') {
            Cache::put($cacheKey, $timezone, self::GEOCODING_CACHE_TTL_SECONDS);

            return $timezone;
        }

        return null;
    }

    private function dayLabel(CarbonImmutable $now): string
    {
        $day = $now->format('l');

        return $now->isWeekend() ? "{$day} (weekend)" : $day;
    }

    private function timeOfDay(int $hour): string
    {
        return match (true) {
            $hour < 5 => 'late night',
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            $hour < 21 => 'evening',
            default => 'night',
        };
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('current_datetime');
    }
}
