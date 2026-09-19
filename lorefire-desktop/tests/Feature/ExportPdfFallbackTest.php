<?php

namespace Tests\Feature;

use App\Jobs\ExportPdf;
use App\Models\Character;
use App\Support\NativePdfPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Laravel\Facades\Shell;
use Native\Laravel\Facades\System;
use Tests\TestCase;
use TypeError;

class ExportPdfFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $dir = storage_path('app/pdf-previews');
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.html') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_printer_returns_null_when_electron_result_is_null(): void
    {
        Http::fake([
            '*' => Http::response(['result' => null], 200),
        ]);

        $printer = new NativePdfPrinter;
        $this->assertNull($printer->printToPdf('<html><body>Sheet</body></html>'));
    }

    public function test_printer_returns_null_when_print_to_pdf_typeerrors(): void
    {
        System::shouldReceive('printToPDF')
            ->once()
            ->andThrow(new TypeError('Native\\Laravel\\System::printToPDF(): Return value must be of type string, null returned'));

        $printer = new NativePdfPrinter;
        $this->assertNull($printer->printToPdf('<html><body>Sheet</body></html>'));
    }

    public function test_printer_returns_base64_when_electron_succeeds(): void
    {
        $pdf = base64_encode('%PDF-1.4 test');

        Http::fake([
            '*' => Http::response(['result' => $pdf], 200),
        ]);

        $printer = new NativePdfPrinter;
        $this->assertSame($pdf, $printer->printToPdf('<html><body>Sheet</body></html>'));
    }

    public function test_printer_url_encodes_html_for_current_electron_plugin(): void
    {
        Http::fake([
            '*' => Http::response(['result' => base64_encode('%PDF-1.4')], 200),
        ]);

        $html = '<html><body>Aelindra &amp; co</body></html>';
        (new NativePdfPrinter)->printToPdf($html);

        Http::assertSent(function ($request) use ($html) {
            return str_contains($request->url(), 'print-to-pdf')
                && $request['html'] === rawurlencode($html);
        });
    }

    public function test_job_falls_back_to_preview_when_print_to_pdf_returns_null(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->once()->andReturn(null);
        });

        $job = $this->makeJob('<html><body><div class="sheet">Thorin</div></body></html>');
        $job->handle(app(NativePdfPrinter::class));

        $status = Cache::get($job->cacheKey);
        $this->assertSame('preview', $status['status']);
        $this->assertStringContainsString('/pdf-export/preview?key=', $status['preview_url']);
        $this->assertFileDoesNotExist($job->htmlPath);
        $this->assertFileExists($job->previewStoragePath());
        $this->assertStringContainsString('Thorin', file_get_contents($job->previewStoragePath()));
    }

    public function test_job_falls_back_to_preview_when_print_to_pdf_typeerrors(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->once()->andThrow(
                new TypeError('Native\\Laravel\\System::printToPDF(): Return value must be of type string, null returned')
            );
        });

        $job = $this->makeJob('<html><body>Cirin</body></html>');
        $job->handle(app(NativePdfPrinter::class));

        $this->assertSame('preview', Cache::get($job->cacheKey)['status']);
    }

    public function test_job_failed_handler_falls_back_to_preview_instead_of_crashing(): void
    {
        $job = $this->makeJob('<html><body>Brom</body></html>');
        $job->failed(new TypeError('Native\\Laravel\\System::printToPDF(): Return value must be of type string, null returned'));

        $status = Cache::get($job->cacheKey);
        $this->assertSame('preview', $status['status']);
        $this->assertFileExists($job->previewStoragePath());
        $this->assertFileDoesNotExist($job->htmlPath);
    }

    public function test_job_writes_pdf_when_print_to_pdf_succeeds(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->once()->andReturn(base64_encode('%PDF-1.4 test'));
        });
        Shell::shouldReceive('openFile')->once();

        $home = sys_get_temp_dir().'/lorefire-pdf-home-'.uniqid();
        mkdir($home.DIRECTORY_SEPARATOR.'Downloads', 0755, true);
        $previousHome = getenv('HOME');
        $previousProfile = getenv('USERPROFILE');
        putenv('HOME='.$home);
        putenv('USERPROFILE='.$home);

        try {
            $job = $this->makeJob('<html><body>Elanor</body></html>', 'elanor.pdf');
            $job->handle(app(NativePdfPrinter::class));

            $this->assertSame('done', Cache::get($job->cacheKey)['status']);
            $this->assertFileExists($home.DIRECTORY_SEPARATOR.'Downloads'.DIRECTORY_SEPARATOR.'elanor.pdf');
            $this->assertSame('%PDF-1.4 test', file_get_contents($home.DIRECTORY_SEPARATOR.'Downloads'.DIRECTORY_SEPARATOR.'elanor.pdf'));
        } finally {
            putenv('HOME='.($previousHome === false ? '' : $previousHome));
            putenv('USERPROFILE='.($previousProfile === false ? '' : $previousProfile));
        }
    }

    public function test_preview_route_serves_combined_sheets_with_print_action(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->andReturn(null);
        });

        $character = Character::factory()->create(['name' => 'Aelindra']);

        $response = $this->postJson(route('batch-sheets.export'), [
            'character_ids' => [$character->id],
        ]);
        $response->assertOk();
        $key = $response->json('key');

        $this->getJson(route('pdf-export.status', ['key' => $key]))
            ->assertOk()
            ->assertJsonPath('status', 'preview');

        $this->get(route('pdf-export.preview', ['key' => $key]))
            ->assertOk()
            ->assertSee('Aelindra', false)
            ->assertSee('Print / Save as PDF', false)
            ->assertSee('pdf-preview-toolbar', false)
            ->assertSee('data-print-url', false)
            ->assertSee('/pdf-export/print', false)
            ->assertDontSee('window.print()', false)
            ->assertDontSee('Edit Character', false);
    }

    public function test_preview_route_returns_404_for_unknown_key(): void
    {
        $this->get(route('pdf-export.preview', ['key' => 'pdf_export_missing']))
            ->assertNotFound();
    }

    public function test_preview_route_returns_404_when_status_is_not_preview(): void
    {
        Cache::put('pdf_export_donekey', ['status' => 'done', 'filename' => 'x.pdf'], 60);

        $this->get(route('pdf-export.preview', ['key' => 'pdf_export_donekey']))
            ->assertNotFound();
    }

    public function test_batch_export_does_not_crash_when_print_to_pdf_is_unavailable(): void
    {
        Http::fake([
            '*' => Http::response(['result' => null], 200),
        ]);

        $character = Character::factory()->create(['name' => 'Davon']);

        $this->postJson(route('batch-sheets.export'), [
            'character_ids' => [$character->id],
        ])
            ->assertOk()
            ->assertJsonStructure(['key']);
    }

    public function test_print_with_os_dialog_posts_silent_false_to_nativephp(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertTrue((new NativePdfPrinter)->printWithOsDialog('<html><body>Sheet</body></html>'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'system/print')
                && ($request['settings']['silent'] ?? true) === false;
        });
    }

    public function test_print_with_os_dialog_returns_false_when_electron_is_down(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Failed to connect');
        });

        $this->assertFalse((new NativePdfPrinter)->printWithOsDialog('<html></html>'));
    }

    public function test_preview_print_uses_native_dialog_when_it_succeeds(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->andReturn(null);
            $mock->shouldReceive('printWithOsDialog')->once()->andReturn(true);
            $mock->shouldReceive('openHtmlInSystemBrowser')->never();
        });

        $character = Character::factory()->create(['name' => 'Thorin']);
        $key = $this->postJson(route('batch-sheets.export'), [
            'character_ids' => [$character->id],
        ])->assertOk()->json('key');

        $this->postJson(route('pdf-export.print'), ['key' => $key])
            ->assertOk()
            ->assertJsonPath('method', 'native-print');
    }

    public function test_preview_print_opens_system_browser_when_native_print_fails(): void
    {
        $this->mock(NativePdfPrinter::class, function ($mock) {
            $mock->shouldReceive('printToPdf')->andReturn(null);
            $mock->shouldReceive('printWithOsDialog')->once()->andReturn(false);
            $mock->shouldReceive('openHtmlInSystemBrowser')
                ->once()
                ->andReturnUsing(function (string $html, string $basename) {
                    $this->assertStringContainsString('Brom', $html);
                    $this->assertStringNotContainsString('pdf-preview-toolbar', $html);
                    $this->assertStringNotContainsString('window.print()', $html);

                    return storage_path('app/pdf-previews/'.$basename.'.html');
                });
        });

        $character = Character::factory()->create(['name' => 'Brom']);
        $key = $this->postJson(route('batch-sheets.export'), [
            'character_ids' => [$character->id],
        ])->assertOk()->json('key');

        $this->postJson(route('pdf-export.print'), ['key' => $key])
            ->assertOk()
            ->assertJsonPath('method', 'system-browser')
            ->assertJsonPath('message', 'Opened in your browser. Use Print / Save as PDF there.');
    }

    public function test_open_html_in_system_browser_writes_sheet_file_without_toolbar(): void
    {
        $printer = \Mockery::mock(NativePdfPrinter::class)->makePartial();
        $printer->shouldReceive('openPath')->once()->andReturn(true);

        $path = $printer->openHtmlInSystemBrowser('<html><body>Cirin</body></html>', 'cirin-sheet');

        $this->assertFileExists($path);
        $this->assertStringEndsWith('.html', $path);
        $this->assertStringContainsString('Cirin', (string) file_get_contents($path));
        $this->assertStringNotContainsString('pdf-preview-toolbar', (string) file_get_contents($path));
    }

    public function test_preview_print_returns_404_without_preview(): void
    {
        $this->postJson(route('pdf-export.print'), ['key' => 'pdf_export_missing'])
            ->assertNotFound();
    }

    private function makeJob(string $html, string $filename = 'sheets.pdf'): ExportPdf
    {
        $key = 'pdf_export_'.str_replace('.', '', uniqid('', true));
        $tmp = tempnam(sys_get_temp_dir(), 'lorefire_pdf_').'.html';
        file_put_contents($tmp, $html);
        Cache::put($key, ['status' => 'pending'], now()->addMinutes(10));

        return new ExportPdf($key, $filename, $tmp);
    }
}
