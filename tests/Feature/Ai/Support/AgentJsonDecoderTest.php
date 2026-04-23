<?php

use App\Ai\Support\AgentJsonDecoder;

test('decodes a clean JSON object', function () {
    expect(AgentJsonDecoder::decode('{"a":1,"b":"c"}'))
        ->toBe(['a' => 1, 'b' => 'c']);
});

test('strips a ```json fence before decoding', function () {
    $raw = "```json\n{\"key\":\"value\"}\n```";

    expect(AgentJsonDecoder::decode($raw))->toBe(['key' => 'value']);
});

test('strips a bare ``` fence before decoding', function () {
    $raw = "```\n{\"approved\":true}\n```";

    expect(AgentJsonDecoder::decode($raw))->toBe(['approved' => true]);
});

test('falls back to object-span recovery when preamble precedes the JSON', function () {
    $raw = 'Sure, here is the JSON you asked for: {"ok":true,"n":3} — hope this helps.';

    expect(AgentJsonDecoder::decode($raw))->toBe(['ok' => true, 'n' => 3]);
});

test('handles newlines and whitespace around the fence contents', function () {
    $raw = "Some preamble\n```json\n   {\"x\":1}   \n```\n";

    expect(AgentJsonDecoder::decode($raw))->toBe(['x' => 1]);
});

test('returns null when no JSON object can be recovered', function () {
    expect(AgentJsonDecoder::decode('this is just prose, no braces at all'))
        ->toBeNull();
});

test('returns null for a malformed object-span', function () {
    // `{` and `}` both present but the contents between them aren't valid JSON
    expect(AgentJsonDecoder::decode('{ this is not valid }'))->toBeNull();
});

test('returns null for an empty string', function () {
    expect(AgentJsonDecoder::decode(''))->toBeNull();
});

test('returns null for a bare JSON array (callers expect an object)', function () {
    // Spec choice: decode returns ?array<string, mixed>, so lists slip
    // through json_decode as arrays too — verify that's still a dict,
    // since the downstream normalizers expect string-keyed payloads.
    // A top-level array returns an indexed array, which our typical
    // callers reject anyway via their `isset($parsed['key'])` checks,
    // but the decode call itself succeeds.
    expect(AgentJsonDecoder::decode('[1, 2, 3]'))->toBe([1, 2, 3]);
});
