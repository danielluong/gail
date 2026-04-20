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

class CurrentLocation implements DisplayableTool, Tool
{
    protected const TIMEOUT_SECONDS = 10;

    protected const ENDPOINT = 'https://ipapi.co';

    public function __construct(
        private readonly ?HostGuard $hostGuard = null,
    ) {}

    public function label(): string
    {
        return 'Checked your location';
    }

    public function description(): Stringable|string
    {
        return 'Get the approximate current geographic location (city, region, country, latitude/longitude, timezone) via public IP lookup. Use this when the user asks about "nearby", "tonight", "near me", or anything that depends on where they are — call it before WebSearch so you can include the city in the query.';
    }

    public function handle(Request $request): Stringable|string
    {
        $ip = trim((string) ($request['ip'] ?? ''));

        $url = $ip === ''
            ? self::ENDPOINT.'/json/'
            : self::ENDPOINT.'/'.rawurlencode($ip).'/json/';

        if ($blocked = $this->guard()->deniedHostFor($url)) {
            return "Error: Requests to '{$blocked}' are blocked for security.";
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($url);
        } catch (Throwable $e) {
            return "Error: Location lookup failed — {$e->getMessage()}";
        }

        if (! $response->successful()) {
            return "Error: Location lookup returned HTTP {$response->status()}.";
        }

        $data = $response->json();

        if (! is_array($data)) {
            return 'Error: Location lookup returned an unexpected response.';
        }

        if (($data['error'] ?? false) === true) {
            $reason = (string) ($data['reason'] ?? 'unknown');

            return "Error: Location lookup failed — {$reason}";
        }

        return $this->format($data);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ip' => $schema->string()
                ->description("Optional IPv4 or IPv6 address to look up. Omit to use the server's public IP (the user's own network).")
                ->required()
                ->nullable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function format(array $data): string
    {
        $city = (string) ($data['city'] ?? '');
        $region = (string) ($data['region'] ?? '');
        $country = (string) ($data['country_name'] ?? ($data['country'] ?? ''));
        $postal = (string) ($data['postal'] ?? '');
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $timezone = (string) ($data['timezone'] ?? '');
        $utcOffset = (string) ($data['utc_offset'] ?? '');
        $ip = (string) ($data['ip'] ?? '');

        $place = array_filter([$city, $region, $country], fn (string $part) => $part !== '');

        $lines = [];

        if ($place !== []) {
            $lines[] = 'Location: '.implode(', ', $place);
        }

        if ($postal !== '') {
            $lines[] = "Postal code: {$postal}";
        }

        if (is_numeric($latitude) && is_numeric($longitude)) {
            $lines[] = "Coordinates: {$latitude}, {$longitude}";
        }

        if ($timezone !== '') {
            $offset = $utcOffset !== '' ? " (UTC {$utcOffset})" : '';
            $lines[] = "Timezone: {$timezone}{$offset}";
        }

        if ($ip !== '') {
            $lines[] = "Resolved from IP: {$ip}";
        }

        if ($lines === []) {
            return 'Location lookup returned no usable fields.';
        }

        return implode("\n", $lines);
    }

    private function guard(): HostGuard
    {
        return $this->hostGuard ?? HostGuard::forTool('current_location');
    }
}
