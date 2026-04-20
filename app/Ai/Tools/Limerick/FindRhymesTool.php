<?php

namespace App\Ai\Tools\Limerick;

use App\Ai\Support\Limerick\Pronunciation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindRhymesTool implements Tool
{
    private const int DEFAULT_LIMIT = 15;

    private const int MAX_LIMIT = 25;

    public function __construct(
        private Pronunciation $pronunciation,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
Find rhymes for a word, optionally filtered by syllable count and stress pattern so the rhyme fits the meter of the line it will close. Always use this instead of guessing rhymes.

The `stress` filter describes the rhyme WORD's own stress, not the metrical foot it sits in. It must have exactly one digit per syllable (1 = stressed, 0 = unstressed), e.g. `"1"` for a 1-syllable word, `"0-1"` for an iamb, `"1-0-1"` for three syllables.

A 1-syllable rhyme is virtually always `stress: "1"` — never `"0"` or `"0-1"`. If you pass `syllables: 1` with a multi-digit stress pattern, nothing will match. When in doubt, omit `stress` and filter by `syllables` alone.
TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $word = strtolower(trim((string) ($request['word'] ?? '')));

        if ($word === '') {
            return json_encode(['seed' => '', 'rhymes' => []]);
        }

        $syllableFilter = isset($request['syllables']) ? (int) $request['syllables'] : null;
        $stressFilter = isset($request['stress']) ? (string) $request['stress'] : null;
        $limit = min((int) ($request['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        if ($syllableFilter !== null && $stressFilter !== null) {
            $stressDigits = preg_match_all('/[01]/', $stressFilter);

            if ($stressDigits !== $syllableFilter) {
                return json_encode([
                    'seed' => $word,
                    'rhymes' => [],
                    'error' => sprintf(
                        'stress "%s" has %d digit(s) but syllables is %d. The stress pattern must have exactly one digit per syllable. Retry with matching values, or omit `stress`.',
                        $stressFilter,
                        $stressDigits,
                        $syllableFilter,
                    ),
                ]);
            }
        }

        $raw = $this->pronunciation->rhymesFor($word, 50);

        $results = [];

        foreach ($raw as $rhyme) {
            $info = $this->pronunciation->lookup($rhyme);

            if ($info === null) {
                continue;
            }

            if ($syllableFilter !== null && $info['syllables'] !== $syllableFilter) {
                continue;
            }

            if ($stressFilter !== null && $info['stress'] !== $stressFilter) {
                continue;
            }

            $results[] = [
                'word' => $rhyme,
                'syllables' => $info['syllables'],
                'stress' => $info['stress'],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return json_encode(['seed' => $word, 'rhymes' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'word' => $schema->string()
                ->description('The word to find rhymes for, e.g. "train" or "away".')
                ->required(),
            'syllables' => $schema->integer()
                ->description('Only return rhymes with this exact syllable count. For a limerick A-line end-rhyme a single-syllable rhyme is common (pass 1).')
                ->required()
                ->nullable(),
            'stress' => $schema->string()
                ->description('Stress pattern of the rhyme word itself, with one digit per syllable (1 = stressed, 0 = unstressed). Must have exactly `syllables` digits — e.g. "1" for 1 syllable, "0-1" for 2 syllables, "1-0-1" for 3. 1-syllable rhymes are almost always "1". If unsure, omit this filter.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of rhymes to return (default 15, max 25).')
                ->required()
                ->nullable(),
        ];
    }
}
