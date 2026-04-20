<?php

namespace App\Support;

/**
 * Null-object stand-in for an authenticated user. Laravel/ai's conversation
 * store just needs an object with an `id` property, so we model the anonymous
 * operator explicitly instead of casting a stdClass inline.
 */
final readonly class GuestUser
{
    public int $id;

    public function __construct()
    {
        $this->id = 0;
    }
}
