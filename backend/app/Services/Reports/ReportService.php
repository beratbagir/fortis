<?php

namespace App\Services\Reports;

use App\Jobs\GenerateReportJob;
use App\Models\Conversation;
use App\Models\Report;
use App\Services\Reports\Generators\CsvGenerator;
use App\Services\Reports\Generators\PdfGenerator;
use App\Services\Reports\Generators\ReportGenerator;
use App\Services\Reports\Generators\XlsxGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Rapor üretimi. Claude sadece ayarları belirler (kaynak, parametreler, format, başlık, sütunlar, sıralama);
 * veriyi Laravel çeker, dosyayı Laravel üretir. Claude'a dönen özet satır verisi içermez.
 */
class ReportService
{
    /** Claude'a giden toplamlarda bir alan için en fazla kategori; gerisi "Diğer". */
    private const MAX_CATEGORIES = 10;

    public function __construct(private readonly ReportSources $sources) {}

    /**
     * Rapor isteğini doğrular, kaydı açar ve üretir (çok satırlıysa kuyruğa atar).
     * Veri çekme hatası ve satır sınırı aşımı kaydı "failed" yapar ve rapor döner.
     *
     * @param  array<string, mixed>  $spec  source, params, format, title, columns, sort_by, sort_dir, group_by
     *
     * @throws ReportException İstek katalogla uyuşmuyorsa (kayıt açılmaz)
     */
    public function create(array $spec, ?Conversation $conversation = null): Report
    {
        $source = $this->sources->source((string) ($spec['source'] ?? '')) ?? throw new ReportException("'".($spec['source'] ?? '')."' adında bir rapor kaynağı yok. list_report_sources ile kaynaklara bak.");
        $params = $this->guard(fn () => $this->sources->checkParams($source, is_array($spec['params'] ?? null) ? $spec['params'] : []));
        $format = $spec['format'] ?? null;
        $columns = $spec['columns'] ?? null;
        $title = trim((string) ($spec['title'] ?? ''));

        if (! in_array($format, config('reports.formats'), true)) {
            throw new ReportException('Format xlsx, csv veya pdf olmalı.');
        }
        if ($title === '' || mb_strlen($title) > 120) {
            throw new ReportException('Rapor başlığı zorunlu (en fazla 120 karakter).');
        }
        if (! is_array($columns) || $columns === [] || ! array_is_list($columns)) {
            throw new ReportException('En az bir sütun seçilmeli.');
        }

        $unknown = array_values(array_diff($columns, array_keys($source['fields'])));
        if ($unknown !== []) {
            throw new ReportException("Bu kaynakta rapora eklenebilecek '".implode("', '", $unknown)."' sütunu yok. Kullanılabilir: ".implode(', ', array_keys($source['fields'])).'.');
        }

        foreach (['sort_by', 'group_by'] as $key) {
            if (isset($spec[$key]) && $spec[$key] !== '' && ! isset($source['fields'][$spec[$key]])) {
                throw new ReportException("{$key}: '{$spec[$key]}' bu kaynakta bir alan değil.");
            }
        }

        $report = Report::create([
            'conversation_id' => $conversation?->id,
            'data_source' => $source['name'],
            'params' => $params,
            'format' => $format,
            'title' => $title,
            'columns' => array_values(array_unique($columns)),
            'sort_by' => ($spec['sort_by'] ?? '') ?: null,
            'sort_dir' => ($spec['sort_dir'] ?? null) === 'desc' ? 'desc' : 'asc',
            'group_by' => ($spec['group_by'] ?? '') ?: null,
            'status' => Report::STATUS_QUEUED,
        ]);

        return $this->run($report);
    }

    /** Süresi dolmuş veya hata vermiş raporu aynı ayarlarla yeniden üretir. */
    public function regenerate(Report $report): Report
    {
        $this->deleteFile($report);
        $report->update(['status' => Report::STATUS_QUEUED, 'error' => null, 'file_path' => null, 'file_size' => null, 'expires_at' => null]);

        return $this->run($report);
    }

    /** Veriyi çeker; az satırsa hemen üretir, çoksa kuyruğa atar. */
    private function run(Report $report): Report
    {
        try {
            $rows = $this->rows($report);
        } catch (Throwable $e) {
            return $this->fail($report, $e);
        }

        $report->update(['row_count' => count($rows), 'aggregates' => $this->aggregates($report, $rows)]);

        if (count($rows) > config('reports.sync_rows')) {
            GenerateReportJob::dispatch($report->id);

            return $report;
        }

        return $this->generate($report, $rows);
    }

    /**
     * Dosyayı üretir ve kaydı "ready" yapar. Kuyruktan çağrıldığında veri yeniden çekilir.
     *
     * @param  list<array<string, mixed>>|null  $rows
     */
    public function generate(Report $report, ?array $rows = null): Report
    {
        try {
            $rows ??= $this->rows($report);
            $source = $this->sources->source($report->data_source);
            $table = new ReportTable(
                title: $report->title,
                columns: array_combine($report->columns, array_map(fn (string $key) => $source['fields'][$key], $report->columns)),
                rows: $this->sort($rows, $report->sort_by, $report->sort_dir),
                filters: $this->filters($source, $report->params),
                groupBy: $report->group_by,
                generatedAt: CarbonImmutable::now(config('reports.timezone')),
            );

            $disk = Storage::disk(config('reports.disk'));
            $path = sprintf('reports/%s/%s.%s', now()->format('Y/m'), $report->id, $report->format);
            $disk->makeDirectory(dirname($path));
            $this->generator($report->format)->write($table, $disk->path($path));
        } catch (Throwable $e) {
            return $this->fail($report, $e);
        }

        $report->update([
            'status' => Report::STATUS_READY,
            'row_count' => count($rows),
            'file_path' => $path,
            'file_size' => $disk->size($path),
            'expires_at' => now()->addDays(config('reports.retention_days')),
            'error' => null,
        ]);

        return $report;
    }

    /** Süresi dolan raporların dosyalarını siler ve kayıtlarını "expired" yapar. */
    public function prune(): int
    {
        $expired = Report::query()->where('status', Report::STATUS_READY)->where('expires_at', '<', now())->get();

        foreach ($expired as $report) {
            $this->deleteFile($report);
            $report->update(['status' => Report::STATUS_EXPIRED, 'file_path' => null, 'file_size' => null]);
        }

        return $expired->count();
    }

    /** @return list<array<string, mixed>> */
    private function rows(Report $report): array
    {
        $source = $this->sources->source($report->data_source) ?? throw new RuntimeException('Rapor kaynağı artık katalogda yok.');

        return $this->sources->fetch($source, $report->params, (int) config('reports.max_rows'));
    }

    private function fail(Report $report, Throwable $e): Report
    {
        // RuntimeException mesajları kendi yazdığımız açıklamalardır; diğer hatalarda ham metin yerine genel mesaj.
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Rapor üretilemedi.';
        report($e);
        $report->update(['status' => Report::STATUS_FAILED, 'error' => mb_substr($message, 0, 500)]);

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows, ?string $by, ?string $dir): array
    {
        if ($by === null) {
            return $rows;
        }

        usort($rows, function (array $a, array $b) use ($by, $dir) {
            $result = ($a[$by] ?? null) <=> ($b[$by] ?? null);

            return $dir === 'desc' ? -$result : $result;
        });

        return $rows;
    }

    /**
     * Claude'a gidebilecek toplamlar: satır sayısı, katalogda toplanabilir işaretli kategori alanlarının sayımları
     * ve sayı alanlarının toplamları. Kişi adı, uçuş no gibi satır düzeyi alanlar katalogda toplanabilir değildir.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregates(Report $report, array $rows): array
    {
        $fields = $this->sources->source($report->data_source)['fields'];
        $aggregates = ['total' => count($rows)];

        foreach ($fields as $key => $field) {
            if (! ($field['aggregatable'] ?? false)) {
                continue;
            }

            if (in_array($field['type'], ['integer', 'number'], true)) {
                $aggregates["sum_{$key}"] = round(array_sum(array_map(fn (array $row) => (float) ($row[$key] ?? 0), $rows)), 2);

                continue;
            }

            $counts = [];
            foreach ($rows as $row) {
                $value = ReportTable::text($row[$key] ?? null, $field['type']) ?: '(boş)';
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
            arsort($counts);

            if (count($counts) > self::MAX_CATEGORIES) {
                $rest = array_sum(array_slice($counts, self::MAX_CATEGORIES));
                $counts = array_slice($counts, 0, self::MAX_CATEGORIES, true) + ['Diğer' => $rest];
            }

            $aggregates["by_{$key}"] = $counts;
        }

        return $aggregates;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function filters(array $source, array $params): array
    {
        $labels = array_column($source['params'], 'label', 'name');

        return collect($params)->mapWithKeys(fn (string $value, string $name) => [$labels[$name] ?? $name => $value])->all();
    }

    private function generator(string $format): ReportGenerator
    {
        return match ($format) {
            'xlsx' => app(XlsxGenerator::class),
            'csv' => app(CsvGenerator::class),
            'pdf' => app(PdfGenerator::class),
        };
    }

    private function deleteFile(Report $report): void
    {
        if ($report->file_path !== null) {
            Storage::disk(config('reports.disk'))->delete($report->file_path);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     */
    private function guard(callable $check): mixed
    {
        try {
            return $check();
        } catch (RuntimeException $e) {
            throw new ReportException($e->getMessage(), previous: $e);
        }
    }
}
