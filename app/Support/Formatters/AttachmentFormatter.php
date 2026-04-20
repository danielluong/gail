<?php

namespace App\Support\Formatters;

/**
 * Shapes stored attachment rows into the UI payload used by the chat
 * history and live SSE stream.
 */
class AttachmentFormatter
{
    /**
     * @param  iterable<int, array<string, mixed>>|null  $attachments
     * @return list<array{name: string, type: ?string, url: ?string}>
     */
    public function format(?iterable $attachments): array
    {
        return collect($attachments ?? [])
            ->map(fn (array $item): array => $this->formatOne($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name: string, type: ?string, url: ?string}
     */
    private function formatOne(array $item): array
    {
        $path = (string) ($item['path'] ?? '');
        $filename = $path !== '' ? basename($path) : '';

        return [
            'name' => (string) ($item['name'] ?? $filename),
            'type' => isset($item['mime']) ? (string) $item['mime'] : null,
            'url' => $filename !== ''
                ? route('uploads.show', ['filename' => $filename])
                : null,
        ];
    }
}
