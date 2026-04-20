<?php

namespace App\Ai\Database;

/**
 * Result of validating a SQL statement against the read-only safety
 * rules. `reason` is populated when `allowed` is false and is safe to
 * return verbatim to the model so it can correct or refuse.
 */
final class SqlValidationResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $firstKeyword = null,
    ) {}

    public static function allow(string $firstKeyword): self
    {
        return new self(true, null, $firstKeyword);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
