<?php

namespace App\Ai\Tools\Limerick;

use App\Ai\Support\Limerick\Pronunciation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PronounceWordTool implements Tool
{
    public function __construct(
        private Pronunciation $pronunciation,
    ) {}

    public function description(): Stringable|string
    {
        return 'Return the syllable count, stress pattern, and ARPAbet pronunciation for a single word. Use this when the validator flags an unknown word or you need to check meter for a specific word.';
    }

    public function handle(Request $request): Stringable|string
    {
        $word = strtolower(trim((string) ($request['word'] ?? '')));

        if ($word === '') {
            return json_encode(['error' => 'empty_word']);
        }

        $result = $this->pronunciation->lookup($word);

        if ($result === null) {
            return json_encode(['word' => $word, 'error' => 'not_found']);
        }

        return json_encode([
            'word' => $word,
            'syllables' => $result['syllables'],
            'stress' => $result['stress'],
            'arpabet' => $result['arpabet'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'word' => $schema->string()
                ->description('The word to look up pronunciation for, e.g. "banana" or "umbrella".')
                ->required(),
        ];
    }
}
