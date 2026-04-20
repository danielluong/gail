<?php

namespace App\Ai\Tools\Chat;

use App\Ai\Contracts\DisplayableTool;
use App\Models\Note;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ManageNotes implements DisplayableTool, Tool
{
    public function label(): string
    {
        return 'Managed notes';
    }

    public function description(): Stringable|string
    {
        return 'Save, search, or delete persistent notes that survive across conversations. Use this to remember facts, preferences, or information the user wants to recall later. Notes are stored by a unique key.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = $request['action'] ?? '';
        $key = trim($request['key'] ?? '');
        $value = $request['value'] ?? '';
        $query = trim($request['query'] ?? '');

        return match ($action) {
            'save' => $this->save($key, $value),
            'search' => $this->search($query),
            'delete' => $this->delete($key),
            default => "Error: Invalid action '{$action}'. Use 'save', 'search', or 'delete'.",
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description("The operation to perform: 'save' to store a note, 'search' to find notes, 'delete' to remove a note.")
                ->enum(['save', 'search', 'delete'])
                ->required(),
            'key' => $schema->string()
                ->description("A short descriptive key for the note (e.g. 'favorite_color', 'server_ip'). Required for save and delete.")
                ->required()
                ->nullable(),
            'value' => $schema->string()
                ->description('The content to store. Required for save.')
                ->required()
                ->nullable(),
            'query' => $schema->string()
                ->description('Search term to find notes by key or value. Required for search.')
                ->required()
                ->nullable(),
        ];
    }

    protected function save(string $key, string $value): string
    {
        if ($key === '') {
            return 'Error: A key is required to save a note.';
        }

        if ($value === '') {
            return 'Error: A value is required to save a note.';
        }

        Note::updateOrCreate(['key' => $key], ['value' => $value]);

        return "Saved note '{$key}'.";
    }

    protected function search(string $query): string
    {
        if ($query === '') {
            $notes = Note::orderBy('updated_at', 'desc')->limit(20)->get();
        } else {
            // Escape SQL LIKE wildcards in the user query so that characters
            // like % or _ are matched literally. The ESCAPE clause tells
            // SQLite/MySQL to honor the backslashes we just added.
            $escaped = addcslashes($query, '\\%_');
            $like = "%{$escaped}%";

            $notes = Note::where(function ($q) use ($like) {
                $q->whereRaw("key LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("value LIKE ? ESCAPE '\\'", [$like]);
            })
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get();
        }

        if ($notes->isEmpty()) {
            return $query === '' ? 'No notes saved yet.' : "No notes found matching '{$query}'.";
        }

        return $notes->map(fn (Note $note) => "- {$note->key}: {$note->value}")->implode("\n");
    }

    protected function delete(string $key): string
    {
        if ($key === '') {
            return 'Error: A key is required to delete a note.';
        }

        $deleted = Note::where('key', $key)->delete();

        return $deleted ? "Deleted note '{$key}'." : "No note found with key '{$key}'.";
    }
}
