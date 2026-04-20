<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Laravel\Ai\Files\Image;

class AttachmentService
{
    private function maxTextSize(): int
    {
        return (int) config('gail.tools.max_output_bytes.attachment_text', 50_000);
    }

    /**
     * Prepare uploaded files for the agent: images become Laravel AI
     * attachments passed straight to the (multimodal) selected model,
     * while PDFs and text-like files are extracted and inlined into the
     * prompt because Ollama's transport only supports images natively.
     *
     * Truncation events are returned as `warnings` so the stream caller
     * can surface them to the user — silently clipping a 200 KB file to
     * 50 KB looks like the model ignoring content.
     *
     * @param  list<string>  $filePaths
     * @return array{message: string, attachments: list<Image>, warnings: list<string>}
     */
    public function prepare(array $filePaths, string $message): array
    {
        $textParts = [];
        $attachments = [];
        $warnings = [];

        foreach ($filePaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $name = basename($path);
            $mime = mime_content_type($path) ?: 'unknown';

            if (str_starts_with($mime, 'image/')) {
                $attachments[] = Image::fromPath($path, $mime);

                continue;
            }

            if ($mime === 'application/pdf') {
                $text = $this->extractPdfText($path, $name, $warnings);
                if ($text !== null) {
                    $textParts[] = "[Attached file: {$name}]\n```\n{$text}\n```";

                    continue;
                }

                $textParts[] = "[Attached PDF: {$name} — install `pdftotext` (poppler) to enable direct PDF reading.]";

                continue;
            }

            if (str_starts_with($mime, 'text/') || in_array($mime, [
                'application/json', 'application/xml', 'application/javascript',
                'application/x-php', 'application/x-sh', 'application/x-yaml',
            ])) {
                $content = (string) file_get_contents($path);
                $content = $this->truncate($content, $name, $warnings);

                $textParts[] = "[Attached file: {$name}]\n```\n{$content}\n```";

                continue;
            }

            $size = round(filesize($path) / 1024, 1);
            $textParts[] = "[Attached file: {$name} ({$mime}, {$size}KB) — this is a binary file and cannot be previewed inline.]";
        }

        $enriched = empty($textParts)
            ? $message
            : implode("\n\n", $textParts)."\n\n".$message;

        return [
            'message' => $enriched,
            'attachments' => $attachments,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    public function extractPdfText(string $path, ?string $name = null, array &$warnings = []): ?string
    {
        $pdftotext = collect(['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'])
            ->first(fn (string $bin) => is_file($bin) || ! str_contains($bin, '/'));

        $result = Process::timeout(10)
            ->run([$pdftotext, $path, '-']);

        if (! $result->successful()) {
            return null;
        }

        $text = trim($result->output());

        if ($text === '') {
            return null;
        }

        return $this->truncate($text, $name ?? basename($path), $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function truncate(string $content, string $name, array &$warnings): string
    {
        $maxBytes = $this->maxTextSize();
        $size = strlen($content);

        if ($size <= $maxBytes) {
            return $content;
        }

        $warnings[] = sprintf(
            '%s was truncated to %s KB (original %s KB) before being sent to the model.',
            $name,
            number_format($maxBytes / 1024, 0),
            number_format($size / 1024, 0),
        );

        return substr($content, 0, $maxBytes)."\n[Truncated]";
    }
}
