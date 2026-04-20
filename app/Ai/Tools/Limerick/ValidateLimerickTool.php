<?php

namespace App\Ai\Tools\Limerick;

use App\Ai\Support\Limerick\MeterSpec;
use App\Ai\Support\Limerick\Pronunciation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ValidateLimerickTool implements Tool
{
    public function __construct(
        private Pronunciation $pronunciation,
    ) {}

    public function description(): Stringable|string
    {
        return 'Validate a complete 5-line limerick for AABBA rhyme scheme and anapestic meter. You MUST call this before returning any limerick to the user and only return the poem once it passes.';
    }

    public function handle(Request $request): Stringable|string
    {
        $lines = $request['lines'] ?? [];

        if (! is_array($lines) || count($lines) !== MeterSpec::LINE_COUNT) {
            return json_encode([
                'ok' => false,
                'error' => 'A limerick must have exactly '.MeterSpec::LINE_COUNT.' lines.',
            ]);
        }

        $lines = array_values(array_map(fn ($l) => trim((string) $l), $lines));

        $lineResults = [];
        $unknownWords = [];
        $endWords = [];

        foreach ($lines as $i => $line) {
            $words = $this->tokenize($line);

            if (count($words) > MeterSpec::MAX_WORDS_PER_LINE) {
                $words = array_slice($words, 0, MeterSpec::MAX_WORDS_PER_LINE);
            }

            $totalSyllables = 0;
            $totalStresses = 0;

            foreach ($words as $word) {
                $info = $this->pronunciation->lookup($word);

                if ($info === null) {
                    $unknownWords[] = $word;
                    $totalSyllables++;

                    continue;
                }

                $totalSyllables += $info['syllables'];
                $totalStresses += substr_count($info['stress'], '1');
            }

            $target = MeterSpec::targetForLine($i);
            $type = MeterSpec::lineType($i);
            $issues = [];

            if ($totalSyllables < $target['min_syllables']) {
                $issues[] = "too short: {$totalSyllables} syllables (need {$target['min_syllables']}-{$target['max_syllables']})";
            } elseif ($totalSyllables > $target['max_syllables']) {
                $issues[] = "too long: {$totalSyllables} syllables (need {$target['min_syllables']}-{$target['max_syllables']})";
            }

            $endWords[$i] = $words !== [] ? end($words) : '';

            $lineResults[] = [
                'index' => $i,
                'syllables' => $totalSyllables,
                'stresses' => $totalStresses,
                'target' => $type,
                'ok' => $issues === [],
                'issues' => $issues,
            ];
        }

        $rhyme = $this->checkRhymeScheme($endWords);

        $allLinesOk = collect($lineResults)->every(fn ($r) => $r['ok']);
        $rhymeOk = $rhyme['a_group_ok'] && $rhyme['b_group_ok'] && $rhyme['a_and_b_distinct'];

        return json_encode([
            'ok' => $allLinesOk && $rhymeOk,
            'rhyme' => $rhyme,
            'lines' => $lineResults,
            'unknown_words' => array_values(array_unique($unknownWords)),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->array()
                ->items($schema->string())
                ->description('Exactly 5 limerick lines in order (lines 1,2,5 rhyme; lines 3,4 rhyme).')
                ->required(),
        ];
    }

    /**
     * @param  array<int, string>  $endWords
     * @return array{a_group_ok: bool, b_group_ok: bool, a_and_b_distinct: bool, details: string|null}
     */
    private function checkRhymeScheme(array $endWords): array
    {
        $aWords = [$endWords[0] ?? '', $endWords[1] ?? '', $endWords[4] ?? ''];
        $bWords = [$endWords[2] ?? '', $endWords[3] ?? ''];

        $aGroupOk = $this->allRhyme($aWords);
        $bGroupOk = $this->allRhyme($bWords);

        $aAndBDistinct = true;
        if ($aWords[0] !== '' && $bWords[0] !== '') {
            $aRhymes = $this->pronunciation->rhymesFor($aWords[0]);
            if (in_array($bWords[0], $aRhymes, true)) {
                $aAndBDistinct = false;
            }
        }

        $details = null;
        if (! $aGroupOk) {
            $details = "Lines 1, 2, and 5 must rhyme (endings: {$aWords[0]}, {$aWords[1]}, {$aWords[2]}).";
        } elseif (! $bGroupOk) {
            $details = "Lines 3 and 4 must rhyme (endings: {$bWords[0]}, {$bWords[1]}).";
        } elseif (! $aAndBDistinct) {
            $details = 'The A-group (lines 1,2,5) and B-group (lines 3,4) endings should not rhyme with each other.';
        }

        return [
            'a_group_ok' => $aGroupOk,
            'b_group_ok' => $bGroupOk,
            'a_and_b_distinct' => $aAndBDistinct,
            'details' => $details,
        ];
    }

    /**
     * @param  list<string>  $words
     */
    private function allRhyme(array $words): bool
    {
        $words = array_filter($words, fn ($w) => $w !== '');

        if (count($words) < 2) {
            return true;
        }

        $anchor = array_shift($words);
        $rhymes = $this->pronunciation->rhymesFor($anchor);

        foreach ($words as $word) {
            if ($word === $anchor) {
                continue;
            }
            if (! in_array($word, $rhymes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $line): array
    {
        $words = preg_split('/[^a-zA-Z\']+/', strtolower($line)) ?: [];

        return array_values(array_filter($words, fn ($w) => $w !== ''));
    }
}
