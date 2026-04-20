<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves CSV files written by ExportQueryCsvTool with an explicit
 * Content-Disposition: attachment header, so the browser downloads
 * the file instead of trying to render it inline. Serving via the
 * raw public-disk URL is fragile — some browsers render CSV as text,
 * others auto-download, and Inertia-intercepted same-origin clicks
 * can bypass a client-side `download` attribute. A dedicated
 * controller route makes the download behavior deterministic and
 * independent of the frontend.
 */
class AiExportController extends Controller
{
    private const DIRECTORY = 'ai-exports';

    public function show(string $filename): StreamedResponse
    {
        $path = self::DIRECTORY.'/'.$filename;
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        // Strip the random prefix (`abc123-`) so the user sees the
        // human filename they asked for in their save dialog.
        $downloadName = preg_replace('/^[A-Za-z0-9]{12}-/', '', $filename) ?? $filename;

        return $disk->download($path, $downloadName, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }
}
