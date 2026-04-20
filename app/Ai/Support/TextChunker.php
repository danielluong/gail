<?php

namespace App\Ai\Support;

class TextChunker
{
    public function __construct(
        private readonly int $chunkSize = 1000,
        private readonly int $overlap = 200,
    ) {}

    /**
     * Split text into overlapping chunks suitable for embedding.
     *
     * Strategy: split on paragraph boundaries first, then sentence
     * boundaries if a paragraph exceeds the chunk size. A sliding
     * window carries `overlap` characters into the next chunk so
     * the embedding model sees context continuity across boundaries.
     *
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->chunkSize) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text);
        $segments = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $this->chunkSize) {
                $segments[] = $paragraph;
            } else {
                array_push($segments, ...$this->splitLongBlock($paragraph));
            }
        }

        return $this->mergeWithOverlap($segments);
    }

    /**
     * @return list<string>
     */
    private function splitLongBlock(string $block): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $block, -1, PREG_SPLIT_NO_EMPTY);

        if (count($sentences) <= 1) {
            return $this->splitBySize($block);
        }

        $segments = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            $candidate = $buffer === '' ? $sentence : $buffer.' '.$sentence;

            if (mb_strlen($candidate) > $this->chunkSize && $buffer !== '') {
                $segments[] = trim($buffer);
                $buffer = $sentence;
            } else {
                $buffer = $candidate;
            }
        }

        $segments[] = trim($buffer);

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function splitBySize(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $chunks[] = trim(mb_substr($text, $offset, $this->chunkSize));
            $offset += $this->chunkSize - $this->overlap;
        }

        return array_filter($chunks, fn (string $c) => $c !== '');
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function mergeWithOverlap(array $segments): array
    {
        $chunks = [];
        $buffer = '';

        foreach ($segments as $segment) {
            $candidate = $buffer === '' ? $segment : $buffer."\n\n".$segment;

            if (mb_strlen($candidate) > $this->chunkSize && $buffer !== '') {
                $chunks[] = $buffer;
                $tail = mb_substr($buffer, -$this->overlap);
                $buffer = trim($tail)."\n\n".$segment;

                if (mb_strlen($buffer) > $this->chunkSize) {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
