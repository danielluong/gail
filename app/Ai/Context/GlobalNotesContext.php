<?php

namespace App\Ai\Context;

use App\Models\Note;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class GlobalNotesContext implements ContextProvider
{
    private const LIMIT = 20;

    private const CACHE_TTL_SECONDS = 300;

    public function render(?Project $project): ?string
    {
        $stamp = (string) (Note::max('updated_at') ?? '0');
        $cacheKey = "gail:context:notes:{$stamp}:".self::LIMIT;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, fn () => $this->build());
    }

    private function build(): ?string
    {
        $notes = Note::query()
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get();

        if ($notes->isEmpty()) {
            return null;
        }

        $list = $notes->map(fn (Note $n) => "- {$n->key}: {$n->value}")->implode("\n");

        return "## Saved Notes (personal memory)\n"
            ."The user has saved these notes previously. Use this information when relevant:\n"
            .$list;
    }
}
