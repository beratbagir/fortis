<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Report;
use App\Services\Apm\ApmApi;
use App\Services\Claude\FlowTools;
use App\Services\Claude\ToolResult;
use App\Services\Reports\ReportService;
use App\Services\Reports\ReportSources;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\SqlGuard;
use App\Services\Warehouse\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * Rapor üretimi (rapor dokümanı, bölüm 3-4; testler 2-5, 7-9).
 */
class ReportTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    /** Veri ambarından dönecek satırlar (ucus_doluluk sorgusunun sütunları). */
    private array $rows = [
        ['ucus' => 'FH1256', 'kalkis' => 'AYT', 'varis' => 'DUS', 'kalkis_saati' => '2026-10-05 02:40:00', 'durum' => 'Uçtu', 'kapasite' => 180, 'binen' => 171, 'doluluk_yuzde' => 95.0],
        ['ucus' => 'FH1193', 'kalkis' => 'AYT', 'varis' => 'HAJ', 'kalkis_saati' => '2026-10-05 07:20:00', 'durum' => 'Uçtu', 'kapasite' => 180, 'binen' => 120, 'doluluk_yuzde' => 66.67],
        ['ucus' => 'FH2001', 'kalkis' => 'İST', 'varis' => 'ŞAM', 'kalkis_saati' => '2026-10-05 23:05:00', 'durum' => 'İptal', 'kapasite' => 189, 'binen' => 0, 'doluluk_yuzde' => 0.0],
    ];

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->useFlowEnvironment();
        $this->conversation = Conversation::factory()->create();

        $test = $this;
        $connection = new class($test)
        {
            public function __construct(private readonly ReportTest $test) {}

            /** @return list<object> */
            public function select(string $sql, array $bindings): array
            {
                return array_map(fn (array $row) => (object) $row, $this->test->rows());
            }
        };

        $this->app->bind(ReportSources::class, fn ($app) => new ReportSources(
            sources: [[
                'name' => 'ibs_ucus_doluluk', 'title' => 'Uçuş doluluğu', 'system' => 'ibs', 'query' => 'ucus_doluluk', 'description' => 'Test',
                'params' => [['name' => 'ucus_tarihi', 'label' => 'Uçuş tarihi', 'type' => 'date', 'required' => true]],
                'fields' => [
                    'ucus' => ['label' => 'Uçuş', 'type' => 'string'],
                    'kalkis' => ['label' => 'Kalkış', 'type' => 'string', 'aggregatable' => true],
                    'varis' => ['label' => 'Varış', 'type' => 'string'],
                    'kalkis_saati' => ['label' => 'Planlanan Kalkış', 'type' => 'datetime'],
                    'durum' => ['label' => 'Durum', 'type' => 'string', 'aggregatable' => true],
                    'binen' => ['label' => 'Binen', 'type' => 'integer', 'aggregatable' => true],
                    'doluluk_yuzde' => ['label' => 'Doluluk (%)', 'type' => 'number'],
                ],
            ]],
            warehouse: new Warehouse(
                connection: fn () => $connection,
                queries: json_decode((string) file_get_contents(resource_path('warehouse/queries.json')), true),
                dictionary: ['hidden_columns' => []],
                guard: new SqlGuard([], 120000, 5000),
            ),
            successFactors: $app->make(SuccessFactors::class),
            apm: $app->make(ApmApi::class),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    private function spec(array $overrides = []): array
    {
        return $overrides + [
            'source' => 'ibs_ucus_doluluk',
            'params' => ['ucus_tarihi' => '2026-10-05'],
            'format' => 'xlsx',
            'title' => 'Dünkü uçuşlar: doluluk',
            'columns' => ['ucus', 'kalkis', 'varis', 'kalkis_saati', 'binen', 'doluluk_yuzde'],
            'sort_by' => 'kalkis_saati',
        ];
    }

    private function tool(array $input): ToolResult
    {
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn($this->conversation);

        return $tools->execute('create_report', $input);
    }

    public function test_report_tool_produces_a_file_and_returns_only_counts_to_claude(): void
    {
        $result = $this->tool($this->spec());

        $this->assertFalse($result->isError, $result->content);
        $this->assertSame('report', $result->card['type']);

        $report = Report::findOrFail($result->card['report_id']);
        $this->assertSame(Report::STATUS_READY, $report->status);
        $this->assertSame(3, $report->row_count);
        $this->assertSame($this->conversation->id, $report->conversation_id);
        $this->assertStringStartsWith('rpt_', $report->id);
        $this->assertMatchesRegularExpression('#^reports/\d{4}/\d{2}/rpt_\w+\.xlsx$#', $report->file_path);
        Storage::disk('local')->assertExists($report->file_path);

        $sent = json_decode($result->content, true);
        $this->assertSame(3, $sent['row_count']);
        $this->assertSame(['AYT' => 2, 'İST' => 1], $sent['aggregates']['by_kalkis']);
        $this->assertEquals(291, $sent['aggregates']['sum_binen']);
        // Satır düzeyindeki veri (uçuş numaraları, varış) Claude'a gitmez.
        foreach (['FH1256', 'FH1193', 'FH2001', 'DUS', 'ŞAM'] as $value) {
            $this->assertStringNotContainsString($value, $result->content);
        }
    }

    public function test_column_outside_the_catalog_is_rejected(): void
    {
        $result = $this->tool($this->spec(['columns' => ['ucus', 'yolcu_tc_kimlik']]));

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('yolcu_tc_kimlik', $result->content);
        $this->assertSame(0, Report::count());

        foreach ([['source' => 'olmayan'], ['format' => 'docx'], ['sort_by' => 'fuel'], ['params' => ['ucus_tarihi' => 'dün']], ['params' => []]] as $bad) {
            $this->assertTrue($this->tool($this->spec($bad))->isError, json_encode($bad));
        }
        $this->assertSame(0, Report::count());
    }

    public function test_row_limit_stops_the_report_with_a_clear_message(): void
    {
        config(['reports.max_rows' => 2]);

        $result = $this->tool($this->spec());

        $this->assertTrue($result->isError);
        $report = Report::sole();
        $this->assertSame(Report::STATUS_FAILED, $report->status);
        $this->assertStringContainsString('daraltın', $report->error);
        $this->assertNull($report->file_path);
        $this->assertSame('REPORT', json_decode($result->content, true)['error']['type']);
    }

    public function test_excel_has_turkish_headers_rows_and_date_format(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);
        $sheet = IOFactory::load(Storage::disk('local')->path($report->file_path))->getActiveSheet();

        $this->assertSame(['Uçuş', 'Kalkış', 'Varış', 'Planlanan Kalkış', 'Binen', 'Doluluk (%)'], $sheet->rangeToArray('A1:F1')[0]);
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:F4', $sheet->getAutoFilter()->getRange());
        $this->assertSame(4, $sheet->getHighestDataRow());
        $this->assertSame('Dünkü uçuşlar doluluk', $sheet->getTitle());

        // Kalkış saatine göre sıralı; tarih gerçek Excel tarihi, dd.mm.yyyy hh:mm
        $this->assertSame('FH1256', $sheet->getCell('A2')->getValue());
        $this->assertSame('dd.mm.yyyy hh:mm', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
        $this->assertSame('05.10.2026 02:40', $sheet->getCell('D2')->getFormattedValue());
        $this->assertSame(171, $sheet->getCell('E2')->getValue());
        $this->assertSame('İST', $sheet->getCell('B4')->getValue());
    }

    public function test_csv_has_bom_semicolons_and_intact_turkish_characters(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec(['format' => 'csv', 'sort_by' => null]), $this->conversation);
        $content = Storage::disk('local')->get($report->file_path);

        $this->assertStringStartsWith("\u{FEFF}", $content);
        $lines = explode("\n", trim(substr($content, 3)));
        $this->assertSame('Uçuş;Kalkış;Varış;"Planlanan Kalkış";Binen;"Doluluk (%)"', $lines[0]);
        $this->assertSame('FH2001;İST;ŞAM;"05.10.2026 23:05";0;0,00', $lines[3]);
        $this->assertCount(4, $lines);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
    }

    public function test_pdf_contains_title_turkish_characters_and_group_summary(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec([
            'format' => 'pdf',
            'title' => 'Doluluk özeti: Ağır İş Şubesi',
            'columns' => ['ucus', 'kalkis', 'varis', 'kalkis_saati', 'durum', 'binen', 'doluluk_yuzde'],
            'group_by' => 'kalkis',
        ]), $this->conversation);

        $this->assertSame(Report::STATUS_READY, $report->status, (string) $report->error);
        $content = Storage::disk('local')->get($report->file_path);
        $this->assertStringStartsWith('%PDF-', $content);

        $text = (new PdfParser)->parseContent($content)->getText();
        $this->assertStringContainsString('Doluluk özeti: Ağır İş Şubesi', $text);
        $this->assertStringContainsString('ŞAM', $text);
        $this->assertStringContainsString('Planlanan Kalkış', $text);
        $this->assertStringContainsString('05.10.2026 23:05', $text);
        // group_by özeti: Kalkış: AYT: 2, İST: 1
        $this->assertMatchesRegularExpression('/AYT:\s*2/', $text);
        $this->assertMatchesRegularExpression('/İST:\s*1/', $text);
        $this->assertStringContainsString('Sayfa 1 / 1', $text);
    }

    public function test_download_is_limited_to_the_conversation_and_client_and_is_logged(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);
        $other = Conversation::factory()->create();

        $this->postJson("/api/reports/{$report->id}/download-link", ['conversation_id' => $other->id])->assertForbidden();

        $url = $this->postJson("/api/reports/{$report->id}/download-link", ['conversation_id' => $this->conversation->id])
            ->assertOk()
            ->json('data.url');

        // Tarayıcılar UTF-8 adı (filename*) kullanır; filename= eski istemciler için ASCII yedek.
        $response = $this->get($url)->assertOk()->assertDownload('Dunku ucuslar doluluk.xlsx');
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('Dünkü uçuşlar doluluk.xlsx'), $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $report->downloads()->count());
        $this->assertSame('127.0.0.1', $report->downloads()->first()->ip);

        // Başka bir istemci aynı bağlantıyla indiremez.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->get($url)->assertForbidden();
        // İmzası bozulmuş bağlantı
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        // Süresi dolmuş bağlantı
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();

        $this->assertSame(1, $report->downloads()->count());
    }

    public function test_download_is_audited(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);
        $url = $this->postJson("/api/reports/{$report->id}/download-link", ['conversation_id' => $this->conversation->id])->json('data.url');

        $this->get($url)->assertOk();

        $log = AuditLog::query()->where('action', 'report.download')->sole();
        $this->assertSame($report->id, $log->subject_id);
        $this->assertSame($this->signedIn->id, $log->user_id);
        $this->assertSame(1, $report->downloads()->count());
    }

    public function test_prune_deletes_expired_files_and_marks_reports_expired(): void
    {
        $report = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);
        $fresh = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);
        $report->update(['expires_at' => now()->subMinute()]);

        $this->artisan('reports:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($report->getOriginal('file_path') ?? 'x');
        $this->assertSame(Report::STATUS_EXPIRED, $report->refresh()->status);
        $this->assertNull($report->file_path);
        $this->assertSame(Report::STATUS_READY, $fresh->refresh()->status);

        // Süresi dolan rapor aynı ayarlarla yeniden oluşturulabilir.
        $this->postJson("/api/reports/{$report->id}/regenerate")->assertOk()->assertJsonPath('data.status', 'ready');
    }

    public function test_large_report_goes_to_the_queue_then_becomes_ready(): void
    {
        config(['reports.sync_rows' => 2]);
        Queue::fake();

        $report = $this->app->make(ReportService::class)->create($this->spec(), $this->conversation);

        $this->assertSame(Report::STATUS_QUEUED, $report->status);
        $this->assertSame(3, $report->row_count);
        Queue::assertPushed(GenerateReportJob::class, fn (GenerateReportJob $job) => $job->reportId === $report->id);

        (new GenerateReportJob($report->id))->handle($this->app->make(ReportService::class));

        $this->assertSame(Report::STATUS_READY, $report->refresh()->status);
        Storage::disk('local')->assertExists($report->file_path);
    }

    public function test_report_card_appears_in_the_conversation_with_live_status(): void
    {
        $result = $this->tool($this->spec());
        $this->conversation->messages()->create(['role' => 'user', 'content' => [], 'text' => '', 'meta' => ['flow_cards' => [$result->card]]]);

        $this->getJson("/api/conversations/{$this->conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.flow_cards.0.type', 'report')
            ->assertJsonPath('data.messages.0.flow_cards.0.status', 'ready')
            ->assertJsonPath('data.messages.0.flow_cards.0.format', 'xlsx')
            ->assertJsonPath('data.messages.0.flow_cards.0.row_count', 3);
    }
}
