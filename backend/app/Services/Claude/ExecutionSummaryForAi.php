<?php

namespace App\Services\Claude;

use App\Models\Report;

/**
 * Bir Kestra çalışmasının Claude'a giden özeti. Claude'a giden bütün çalışma bilgisi bu sınıftan geçer.
 *
 * Satır verisi gitmez: görev çıktıları, loglar, girdiler ve döngü değerleri (taskrun.value) çıkarılır. Hata yalnızca
 * görev adı, hata türü ve sabit bir açıklama olarak gider; Kestra'nın ham hata metni (içinde uçuş numarası, kişi adı
 * olabilir) gitmez. Sadece yapısal parçalar (HTTP kodu, parametre adı, SQLSTATE) desenle süzülerek eklenir.
 */
class ExecutionSummaryForAi
{
    private const FAILED_STATES = ['FAILED', 'KILLED', 'WARNING', 'CANCELLED'];

    /** Yapısal ad (parametre, değişken): harf, rakam, alt çizgi, nokta. Değer içeremez. */
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_.]{0,59}$/';

    /**
     * @param  array<string, mixed>  $execution  Kestra çalışması
     * @param  list<array<string, mixed>>  $logs  Kestra logları (sadece hata türünü bulmak için okunur, gönderilmez)
     * @param  array{report_id: string|null, row_count: int|null, aggregates: array<string, mixed>|null}|null  $report
     * @return array{execution_id: string, flow_id: string|null, status: string|null, duration_seconds: int|null, row_count: int|null, report_id: string|null, aggregates: array<string, mixed>|null, error: array{task: string|null, type: string, message: string}|null}
     */
    public static function fromKestra(array $execution, array $logs = [], ?array $report = null): array
    {
        $status = $execution['state']['current'] ?? null;
        $taskRuns = $execution['taskRunList'] ?? [];

        return [
            'execution_id' => (string) ($execution['id'] ?? ''),
            'flow_id' => $execution['flowId'] ?? null,
            'status' => $status,
            'duration_seconds' => self::seconds($execution['state']['duration'] ?? null),
            'row_count' => $report['row_count'] ?? self::rowCount($taskRuns),
            'report_id' => $report['report_id'] ?? null,
            'aggregates' => $report['aggregates'] ?? null,
            'error' => in_array($status, self::FAILED_STATES, true) ? self::error($status, $taskRuns, $logs) : null,
        ];
    }

    /**
     * Raporun Claude'a giden özeti: kimlik, durum, satır sayısı ve katalogda toplanabilir alanların sayımları.
     * Satırlar ve dosya içeriği gitmez.
     *
     * @return array{report_id: string, status: string, format: string, title: string, row_count: int|null, aggregates: array<string, mixed>|null, error: array{task: null, type: string, message: string}|null}
     */
    public static function forReport(Report $report): array
    {
        return [
            'report_id' => $report->id,
            'status' => $report->status,
            'format' => $report->format,
            'title' => $report->title,
            'row_count' => $report->row_count,
            'aggregates' => $report->aggregates,
            'error' => $report->status === Report::STATUS_FAILED ? ['task' => null, 'type' => 'REPORT', 'message' => (string) $report->error] : null,
        ];
    }

    /**
     * Hata veren adımın temizlenmiş hatası.
     *
     * @param  list<array<string, mixed>>  $taskRuns
     * @param  list<array<string, mixed>>  $logs
     * @return array{task: string|null, type: string, message: string}
     */
    public static function error(?string $status, array $taskRuns, array $logs): array
    {
        $failed = null;
        foreach ($taskRuns as $run) {
            if (in_array($run['state']['current'] ?? null, ['FAILED', 'KILLED'], true)) {
                $failed = $run['taskId'] ?? null;
                break;
            }
        }

        $errors = array_values(array_filter($logs, fn (array $log) => ($log['level'] ?? null) === 'ERROR'
            && ($failed === null || ($log['taskId'] ?? $log['task_id'] ?? null) === $failed)));
        $raw = (string) (end($errors)['message'] ?? '');

        if ($status === 'KILLED' && $raw === '') {
            return ['task' => $failed, 'type' => 'KILLED', 'message' => 'Çalışma durduruldu (kullanıcı reddetti veya durdurdu).'];
        }

        return ['task' => $failed] + self::classify($raw);
    }

    /**
     * Ham hata metninden hata türü ve sabit açıklama. Metnin kendisi döndürülmez.
     *
     * @return array{type: string, message: string}
     */
    public static function classify(string $raw): array
    {
        if (preg_match('/(?:response code|status code|HTTP(?: status)?)\\D{0,3}(\\d{3})/i', $raw, $m)) {
            $code = (int) $m[1];
            $fields = self::invalidFields($raw);
            $message = match (true) {
                $code === 401 || $code === 403 => "Dış sistem yetki hatası verdi ({$code}); kimlik bilgisi (secret) veya yetki sorunu.",
                $code === 404 => 'Dış sistem bu adresi bulamadı (404); uç veya kayıt yok.',
                $code === 429 => 'Dış sistem istek sınırına takıldı (429).',
                $code >= 500 => "Dış sistem kendi tarafında hata verdi ({$code}).",
                default => "Dış sistem isteği reddetti ({$code}).",
            };

            return ['type' => 'HTTP_'.$code, 'message' => $message.($fields === [] ? '' : ' Geçersiz parametre: '.implode(', ', $fields).'.')];
        }

        if (preg_match('/Connection refused|UnknownHost|unknown host|connect timed out|Connect timeout|timed out|No route to host|ConnectException/i', $raw)) {
            return ['type' => 'CONNECTION', 'message' => 'Dış sisteme ulaşılamadı (bağlantı veya zaman aşımı). Tünel, VPN veya sunucu kapalı olabilir.'];
        }

        if (preg_match('/SQLSTATE\[(\w{5})\]|SQLException|SQLSyntax/i', $raw, $m)) {
            return ['type' => 'QUERY', 'message' => 'Veri ambarı sorgusu hata verdi'.(isset($m[1]) ? " (SQLSTATE {$m[1]})" : '').'.'];
        }

        if (preg_match('/jq|JsonQuery|jackson/i', $raw)) {
            return ['type' => 'TRANSFORM', 'message' => 'Yanıtı dönüştüren jq ifadesi hata verdi; yanıtın yapısı beklenenden farklı olabilir.'];
        }

        if (preg_match('/Pebble|Unable to (?:find|render)|expression|IllegalVariable|Missing variable/i', $raw)) {
            $variable = preg_match('/Unable to find `([^`]+)`/', $raw, $v) && preg_match(self::NAME, $v[1]) ? " (`{$v[1]}`)" : '';

            return ['type' => 'EXPRESSION', 'message' => "Şablon ifadesi ({{ }}) çözülemedi{$variable}; olmayan bir değişken veya çıktı kullanılıyor olabilir."];
        }

        if (preg_match('/io\.kestra\.plugin\.core\.execution\.Fail|Fail task|failed by user/i', $raw)) {
            return ['type' => 'FAILED_BY_FLOW', 'message' => 'Akış tasarlandığı gibi bilinçli olarak hata verdi (Fail adımı).'];
        }

        return ['type' => 'UNKNOWN', 'message' => $raw === '' ? 'Adım hata verdi; hata mesajı yok.' : 'Adım hata verdi. Ayrıntı Çalışmalar ekranındaki loglarda.'];
    }

    /**
     * Yanıttaki doğrulama hatalarının alan adları (ör. {"field":"size","message":"Unknown parameter"} → size).
     *
     * @return list<string>
     */
    private static function invalidFields(string $raw): array
    {
        preg_match_all('/"(?:field|parameter|param)"\s*:\s*"([^"]{1,60})"/', $raw, $m);

        return array_values(array_unique(array_filter($m[1], fn (string $name) => (bool) preg_match(self::NAME, $name))));
    }

    /**
     * Akışın işlediği satır sayısı: döngünün çalıştığı eleman sayısı (en çok elemanlı döngü); döngü yoksa sorgu
     * çıktısındaki satır sayısı. Bilinmiyorsa null. Değerlerin kendisi kullanılmaz, sadece sayılır.
     *
     * @param  list<array<string, mixed>>  $taskRuns
     */
    public static function rowCount(array $taskRuns): ?int
    {
        $values = [];
        $sizes = [];

        foreach ($taskRuns as $run) {
            if (isset($run['value']) && ($run['value'] !== '')) {
                $values[$run['taskId'] ?? ''][md5((string) $run['value'])] = true;
            }

            $outputs = $run['outputs'] ?? null;
            if (is_array($outputs)) {
                if (isset($outputs['size']) && is_numeric($outputs['size'])) {
                    $sizes[] = (int) $outputs['size'];
                } elseif (isset($outputs['rows']) && is_array($outputs['rows'])) {
                    $sizes[] = count($outputs['rows']);
                }
            }
        }

        if ($values !== []) {
            return max(array_map('count', $values));
        }

        return $sizes === [] ? null : max($sizes);
    }

    /** "PT4.2S", "PT1M3S" → saniye */
    private static function seconds(?string $iso): ?int
    {
        if ($iso === null || ! preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?$/', $iso, $m)) {
            return null;
        }

        return (int) round(((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (float) ($m[3] ?? 0));
    }
}
