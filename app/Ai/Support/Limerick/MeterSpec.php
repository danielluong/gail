<?php

namespace App\Ai\Support\Limerick;

use InvalidArgumentException;

final class MeterSpec
{
    public const int A_MIN_SYLLABLES = 7;

    public const int A_MAX_SYLLABLES = 10;

    public const int A_STRESSES = 3;

    public const int B_MIN_SYLLABLES = 5;

    public const int B_MAX_SYLLABLES = 7;

    public const int B_STRESSES = 2;

    public const int MAX_WORDS_PER_LINE = 15;

    public const int LINE_COUNT = 5;

    /**
     * @return array{min_syllables: int, max_syllables: int, stresses: int}
     */
    public static function targetForLine(int $index): array
    {
        return match ($index) {
            0, 1, 4 => [
                'min_syllables' => self::A_MIN_SYLLABLES,
                'max_syllables' => self::A_MAX_SYLLABLES,
                'stresses' => self::A_STRESSES,
            ],
            2, 3 => [
                'min_syllables' => self::B_MIN_SYLLABLES,
                'max_syllables' => self::B_MAX_SYLLABLES,
                'stresses' => self::B_STRESSES,
            ],
            default => throw new InvalidArgumentException("Line index {$index} is outside the 0-4 range."),
        };
    }

    public static function lineType(int $index): string
    {
        return match ($index) {
            0, 1, 4 => 'A',
            2, 3 => 'B',
            default => throw new InvalidArgumentException("Line index {$index} is outside the 0-4 range."),
        };
    }
}
