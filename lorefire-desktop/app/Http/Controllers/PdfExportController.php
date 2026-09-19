<?php

namespace App\Http\Controllers;

use App\Jobs\ExportPdf;
use App\Models\Campaign;
use App\Models\GameSession;
use App\Support\NativePdfPrinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

class PdfExportController extends Controller
{
    private CommonMarkConverter $markdown;

    public function __construct()
    {
        $this->markdown = new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Dispatch a PDF export job for a single session.
     * Returns immediately with a cache key the client can poll.
     */
    public function session(Campaign $campaign, GameSession $session): JsonResponse
    {
        $session->load('sceneArtPrompts');

        $html = view('pdf.session', [
            'campaign' => $campaign,
            'session'  => $session,
            'scenes'   => $session->sceneArtPrompts()->whereNotNull('image_path')->get(),
            'sections' => $this->parseSummaryIntoSections($session->summary ?? ''),
            'baseUrl'  => rtrim(url('/'), '/'),
        ])->render();

        $filename = $this->slugify($campaign->name . ' - ' . $session->title) . '.pdf';

        return response()->json(['key' => $this->enqueue($html, $filename)]);
    }

    /**
     * Dispatch a PDF export job for a full campaign chronicle.
     * Returns immediately with a cache key the client can poll.
     */
    public function campaign(Campaign $campaign): JsonResponse
    {
        $sessions = $campaign->gameSessions()
            ->whereNotNull('summary')
            ->orderBy('played_at')
            ->orderBy('session_number')
            ->with('sceneArtPrompts')
            ->get();

        // Pre-parse sections for every session so the template gets rendered HTML
        $sessionSections = $sessions->mapWithKeys(function ($session) {
            return [$session->id => $this->parseSummaryIntoSections($session->summary ?? '')];
        });

        $html = view('pdf.campaign', [
            'campaign'        => $campaign,
            'sessions'        => $sessions,
            'sessionSections' => $sessionSections,
            'baseUrl'         => rtrim(url('/'), '/'),
        ])->render();

        $filename = $this->slugify($campaign->name . ' - Chronicle') . '.pdf';

        return response()->json(['key' => $this->enqueue($html, $filename)]);
    }

    /**
     * Poll the status of a PDF export job.
     * Returns { status: 'pending' | 'done' | 'failed' | 'preview', filename?, error?, preview_url?, message? }
     */
    public function status(Request $request): JsonResponse
    {
        $key  = $request->query('key');
        $data = Cache::get($key);

        return response()->json($data ?? ['status' => 'pending']);
    }

    /**
     * Serve a local print-preview of the combined HTML when NativePHP
     * printToPDF is unavailable. The page is print-friendly (Save as PDF).
     */
    public function preview(Request $request): \Illuminate\Http\Response
    {
        [$key, $html] = $this->resolvePreviewHtml($request);

        $html = $this->injectPrintChrome($html, $key);

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Print action for the preview toolbar. Tries NativePHP OS print dialog,
     * then opens the combined sheets in the system default browser.
     */
    public function print(Request $request, NativePdfPrinter $printer): JsonResponse
    {
        [, $html, $data] = $this->resolvePreviewHtml($request);

        $filename = (string) ($data['filename'] ?? 'character-sheets.pdf');
        $basename = preg_replace('/\.pdf$/i', '', $filename) ?: 'character-sheets';

        if ($printer->printWithOsDialog($html)) {
            return response()->json([
                'method'  => 'native-print',
                'message' => 'Print dialog opened.',
            ]);
        }

        $path = $printer->openHtmlInSystemBrowser($html, $basename);

        return response()->json([
            'method'  => 'system-browser',
            'message' => 'Opened in your browser. Use Print / Save as PDF there.',
            'file'    => basename($path),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: array}
     */
    private function resolvePreviewHtml(Request $request): array
    {
        $key = (string) $request->input('key', $request->query('key', ''));
        if ($key === '' || ! preg_match('/^pdf_export_[a-zA-Z0-9_-]+$/', $key)) {
            abort(404);
        }

        $data = Cache::get($key);
        if (! is_array($data) || ($data['status'] ?? '') !== 'preview') {
            abort(404);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
        $path = storage_path('app/pdf-previews/'.$safe.'.html');
        if (! is_file($path)) {
            abort(404);
        }

        return [$key, (string) file_get_contents($path), $data];
    }

    private function injectPrintChrome(string $html, string $key): string
    {
        $token = e(csrf_token());
        $keyJs = e($key);
        $printUrl = e(url('/pdf-export/print'));

        $chrome = <<<HTML
<style id="pdf-preview-chrome-style">
  .pdf-preview-toolbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 16px;
    padding: 8px 12px;
    background: #e8e4dc;
    border-bottom: 2px solid #111;
    color: #111;
    font-family: Georgia, "Times New Roman", Times, serif;
    font-size: 13px;
  }
  .pdf-preview-toolbar p { margin: 0; flex: 1 1 240px; }
  .pdf-preview-toolbar button {
    cursor: pointer;
    border: 1px solid #111;
    background: #fff;
    color: #111;
    padding: 6px 14px;
    font-size: 13px;
    font-family: Georgia, "Times New Roman", Times, serif;
  }
  .pdf-preview-toolbar button:hover { background: #f3f0ea; }
  .pdf-preview-toolbar button:disabled { opacity: 0.5; cursor: wait; }
  @media print {
    .pdf-preview-toolbar { display: none !important; }
  }
</style>
<div class="pdf-preview-toolbar">
  <p id="pdf-print-status">Print opens the system print dialog, or your browser if needed. This bar is not part of the sheet.</p>
  <button type="button" id="pdf-print-btn" data-print-url="{$printUrl}" data-print-key="{$keyJs}" data-csrf="{$token}">Print / Save as PDF</button>
  <button type="button" onclick="history.back()">Back</button>
</div>
<script>
(function () {
  var btn = document.getElementById('pdf-print-btn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var status = document.getElementById('pdf-print-status');
    btn.disabled = true;
    if (status) status.textContent = 'Opening print…';
    fetch(btn.getAttribute('data-print-url'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': btn.getAttribute('data-csrf') || '',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ key: btn.getAttribute('data-print-key') })
    }).then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (result) {
        if (status) status.textContent = (result.data && result.data.message) ? result.data.message : (result.ok ? 'Print started.' : 'Print failed.');
      })
      .catch(function () {
        if (status) status.textContent = 'Print failed.';
      })
      .finally(function () { btn.disabled = false; });
  });
})();
</script>
HTML;

        if (preg_match('/<body[^>]*>/i', $html)) {
            return (string) preg_replace('/<body[^>]*>/i', '$0'.$chrome, $html, 1);
        }

        return $chrome.$html;
    }

    /**
     * Split a markdown summary into sections, each with:
     *   - headingHtml: rendered <h2> string or null for a preamble
     *   - bodyHtml:    rendered HTML of the section body paragraphs
     *
     * Sections are split on # / ## headings. The heading itself is rendered
     * separately so the Blade template can inject a scene image between the
     * heading+body block and the next heading.
     */
    private function parseSummaryIntoSections(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        // Split raw markdown lines into chunks at every # / ## boundary
        $rawSections = [];
        $current     = ['heading' => null, 'lines' => []];

        foreach (explode("\n", $markdown) as $line) {
            $t = trim($line);
            if (str_starts_with($t, '### ') || str_starts_with($t, '## ') || str_starts_with($t, '# ')) {
                if ($current['heading'] !== null || count($current['lines']) > 0) {
                    $rawSections[] = $current;
                }
                if (str_starts_with($t, '### ')) {
                    $headingText = substr($t, 4);
                } elseif (str_starts_with($t, '## ')) {
                    $headingText = substr($t, 3);
                } else {
                    $headingText = substr($t, 2);
                }
                $current = ['heading' => trim($headingText), 'lines' => []];
            } else {
                $current['lines'][] = $line; // keep original line (not trimmed) for markdown fidelity
            }
        }
        if ($current['heading'] !== null || count($current['lines']) > 0) {
            $rawSections[] = $current;
        }

        // Render each section's body through CommonMark
        return array_map(function (array $sec) {
            $bodyMarkdown = implode("\n", $sec['lines']);
            $bodyHtml     = trim((string) $this->markdown->convert($bodyMarkdown));

            return [
                'headingText' => $sec['heading'],           // plain text, for display
                'headingHtml' => $sec['heading']
                    ? '<h2>' . e($sec['heading']) . '</h2>'
                    : null,
                'bodyHtml'    => $bodyHtml,
            ];
        }, $rawSections);
    }

    /**
     * Write HTML to a temp file, seed the cache key as pending, dispatch the job.
     */
    private function enqueue(string $html, string $filename): string
    {
        $key     = 'pdf_export_' . Str::uuid()->toString();
        $tmpHtml = tempnam(sys_get_temp_dir(), 'lorefire_pdf_') . '.html';
        file_put_contents($tmpHtml, $html);

        Cache::put($key, ['status' => 'pending'], now()->addMinutes(10));

        ExportPdf::dispatch($key, $filename, $tmpHtml);

        return $key;
    }

    private function slugify(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
    }
}
