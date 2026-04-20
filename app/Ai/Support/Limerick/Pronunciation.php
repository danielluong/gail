<?php

namespace App\Ai\Support\Limerick;

use Illuminate\Support\Facades\Http;

class Pronunciation
{
    private const string DATAMUSE_URL = 'https://api.datamuse.com/words';

    /**
     * @var array<string, array{syllables: int, stress: string, arpabet: string}|null>
     */
    private array $cache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $rhymeCache = [];

    /**
     * @return array{syllables: int, stress: string, arpabet: string}|null
     */
    public function lookup(string $word): ?array
    {
        $word = strtolower(trim($word));

        if ($word === '') {
            return null;
        }

        if (array_key_exists($word, $this->cache)) {
            return $this->cache[$word];
        }

        $response = Http::get(self::DATAMUSE_URL, [
            'sp' => $word,
            'md' => 'r,s',
            'max' => 1,
        ]);

        if (! $response->ok()) {
            $this->cache[$word] = null;

            return null;
        }

        $results = $response->json();

        if (! is_array($results) || $results === []) {
            $this->cache[$word] = null;

            return null;
        }

        $entry = $results[0];
        $tags = $entry['tags'] ?? [];
        $arpabet = $this->extractArpabet($tags);

        if ($arpabet === null) {
            $syllables = $this->extractSyllableCount($tags);
            $this->cache[$word] = $syllables !== null
                ? ['syllables' => $syllables, 'stress' => str_repeat('1', $syllables), 'arpabet' => '']
                : null;

            return $this->cache[$word];
        }

        $stress = $this->parseStress($arpabet);
        $syllables = $stress !== '' ? substr_count($stress, '0') + substr_count($stress, '1') + substr_count($stress, '2') : 1;

        $normalizedStress = str_replace('2', '1', $stress);

        $this->cache[$word] = [
            'syllables' => $syllables,
            'stress' => $normalizedStress,
            'arpabet' => $arpabet,
        ];

        return $this->cache[$word];
    }

    public function syllables(string $word): int
    {
        return $this->lookup($word)['syllables'] ?? 1;
    }

    public function stressPattern(string $word): ?string
    {
        return $this->lookup($word)['stress'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function rhymesFor(string $word, int $max = 25): array
    {
        $word = strtolower(trim($word));

        if ($word === '') {
            return [];
        }

        if (array_key_exists($word, $this->rhymeCache)) {
            return $this->rhymeCache[$word];
        }

        $response = Http::get(self::DATAMUSE_URL, [
            'rel_rhy' => $word,
            'max' => $max,
        ]);

        if (! $response->ok()) {
            $this->rhymeCache[$word] = [];

            return [];
        }

        $rhymes = collect($response->json())
            ->pluck('word')
            ->filter(fn ($w) => is_string($w) && strlen($w) >= 2)
            ->unique()
            ->values()
            ->all();

        $this->rhymeCache[$word] = $rhymes;

        return $rhymes;
    }

    /**
     * @param  list<mixed>  $tags
     */
    private function extractArpabet(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'pron:')) {
                return substr($tag, 5);
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $tags
     */
    private function extractSyllableCount(array $tags): ?int
    {
        foreach ($tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'f:')) {
                $freq = (float) substr($tag, 2);

                // Not a syllable count tag
                if ($freq > 0) {
                    continue;
                }
            }

            if (is_string($tag) && preg_match('/^(\d+) syl/', $tag, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function parseStress(string $arpabet): string
    {
        preg_match_all('/[012]/', $arpabet, $matches);

        return implode('-', $matches[0]);
    }
}
