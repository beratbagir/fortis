<?php

namespace App\Ai;

use App\Models\AuditLog;
use App\Services\Apm\ApmApi;
use App\Services\Auth\Audit;
use App\Services\Claude\ToolResult;
use App\Services\Settings\AppSettings;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Claude'un şirket verisini sorgulayıp görebildiği araçlar: IBS veri ambarı, APM, SuccessFactors.
 *
 * Sadece okuma yapılır. Her sonuç AiDataGate'ten geçer (maskeleme, satır/karakter sınırı), her sorgu denetim kaydına
 * yazılır (sorgu meta'da, dönen veri yok). Ayar kapalıyken (ai.data_access = off) araçlar Claude'a hiç sunulmaz.
 */
class DataQueryTools
{
    public const TOOLS = ['query_warehouse', 'query_apm', 'query_successfactors'];

    /** SuccessFactors'tan Claude'a dönebilecek alanlar (izin listesi, alan adının son parçası). */
    private const SF_ALLOWED_FIELDS = [
        'userid', 'personidexternal', 'firstname', 'lastname', 'middlename', 'displayname', 'defaultfullname', 'email',
        'department', 'division', 'position', 'positiontitle', 'jobtitle', 'jobcode', 'title', 'location', 'company',
        'businessunit', 'costcenter', 'startdate', 'hiredate', 'originalstartdate', 'enddate', 'emplstatus', 'managerid',
        'eventreason', 'event', 'externalcode', 'name', 'name_defaultvalue', 'name_en_us', 'name_tr_tr', 'description', 'status',
    ];

    /** Kişi kimliğiyle gruplamayı gösteren sütun parçaları (yasaklı tablolarda GROUP BY'da olamaz). */
    private const PERSON_KEYS = '/(pax|passenger|pnr|ticket|customer|person|traveller|name|email|phone|passport|document|user)/i';

    private int $queries = 0;

    public function __construct(
        private readonly Warehouse $warehouse,
        private readonly ApmApi $apm,
        private readonly SuccessFactors $successFactors,
        private readonly ApmResultShaper $shaper,
        private readonly AiDataGate $gate,
        private readonly AppSettings $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->dataAccessEnabled();
    }

    /** Her kullanıcı mesajının başında: sorgu sayacı sıfırlanır. */
    public function startTurn(): void
    {
        $this->queries = 0;
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $purpose = ['type' => 'string', 'description' => 'Kullanıcıya gösterilecek kısa açıklama, ör. "Dünkü uçuşlar, rota bazında yük faktörü".'];
        $common = ' Sonuç tablo olarak döner: columns, rows, total_rows, truncated, masked_fields, source, query_id. Kişisel bilgiler [MASKED] olarak gelir; maskeli değeri tahmin etme. Bir cevap için en fazla '.config('ai-data.max_queries_per_answer').' sorgu.';

        return [
            [
                'name' => 'query_warehouse',
                'description' => 'IBS veri ambarında (rezervasyon, satış, sefer, kapasite) bir SELECT sorgusu çalıştırır ve sonucu sana gösterir. Önce describe_warehouse ile tabloların sütunlarını ve indekslerini kontrol et; hazır sorgular için get_queries. Kurallar: tek SELECT/WITH, sonunda LIMIT (en fazla '.config('ai-data.warehouse_max_limit').'), değerler :ad yer tutucusuyla parameters alanında, indeksli tarih sütunuyla filtrele. Yolcu düzeyindeki tablolar (pnr_pax, chk_passenger...) sadece COUNT/SUM/AVG ile, kişi kimliğine göre gruplamadan kullanılabilir.'.$common,
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string', 'description' => 'SELECT sorgusu, :ad yer tutucularıyla ve LIMIT ile.'],
                        'parameters' => ['type' => 'object', 'description' => 'Yer tutucu değerleri, ör. {"from": "2026-10-11", "to": "2026-10-12"}.'],
                        'purpose' => $purpose,
                    ],
                    'required' => ['sql', 'purpose'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'query_apm',
                'description' => 'APM\'den (uçuş operasyonları) veri çeker ve düz satırlar olarak gösterir. Sorgulanabilen uçlar: '.collect(ApmResultShaper::OPERATIONS)->map(fn (string $d, string $op) => "{$op}: {$d}")->implode(' | ').' Tarihleri somut yaz (ör. 2026-10-11T00:00:00Z); şablon ({{ }}) kullanma.'.$common,
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Yol ve sorgu, ör. /api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z'],
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST'], 'description' => 'Varsayılan GET. POST sadece OTP raporları için.'],
                        'body' => ['type' => 'object', 'description' => 'OTP raporları için JSON gövde.'],
                        'purpose' => $purpose,
                    ],
                    'required' => ['path', 'purpose'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'query_successfactors',
                'description' => 'SuccessFactors\'tan (İK) çalışan, departman, pozisyon, lokasyon bilgisi çeker. Ad, soyad, departman, pozisyon, lokasyon ve tarihler döner; TC kimlik, telefon, adres, doğum tarihi, maaş hiçbir zaman dönmez, e-posta kısmen maskelenir. Önce describe_successfactors ile alanlara bak. Tek bir sayı için Varlık/$count?$filter=... kullan. Gruplara göre sayım (ör. departmanlara, lokasyonlara göre çalışan sayısı) için group_by ver: tek sorguda bütün grupların sayısı döner (satırlar çekilmez).'.$common,
                'eagerInputStreaming' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => "Taban adresten sonraki kısım, ör. EmpJob?\$filter=company%20eq%20'FHY'&\$select=userId,department,startDate"],
                        'group_by' => ['type' => 'string', 'description' => 'Gruplanmış sayım: gruplanacak alan, ör. department veya departmentNav/name (gezinme alanı / ile). Verilince path\'teki $filter uygulanır, sonuç grup başına sayıdır.'],
                        'purpose' => $purpose,
                    ],
                    'required' => ['path', 'purpose'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function execute(string $name, array $input): ToolResult
    {
        if (! $this->enabled()) {
            return ToolResult::error('Veri sorgulama bu ortamda kapalı. Kullanıcıya rapor (create_report) veya akış öner.');
        }

        $purpose = is_string($input['purpose'] ?? null) ? mb_substr(trim($input['purpose']), 0, 200) : '';
        if ($purpose === '') {
            return ToolResult::invalidInput($input, 'purpose zorunlu: kullanıcıya gösterilecek kısa açıklama.');
        }

        if ($this->queries >= (int) config('ai-data.max_queries_per_answer')) {
            return ToolResult::error('Bu cevap için veri sorgusu sınırı ('.config('ai-data.max_queries_per_answer').') doldu. Yeni sorgu yapma; elindeki sonuçlarla cevap ver ve gerekirse kullanıcıya soruyu daraltmasını öner.');
        }
        $this->queries++;

        $queryId = 'q_'.Str::lower(Str::random(10));

        return match ($name) {
            'query_warehouse' => $this->queryWarehouse($input, $purpose, $queryId),
            'query_apm' => $this->queryApm($input, $purpose, $queryId),
            'query_successfactors' => $this->querySuccessFactors($input, $purpose, $queryId),
            default => ToolResult::error("Bilinmeyen araç: {$name}"),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function queryWarehouse(array $input, string $purpose, string $queryId): ToolResult
    {
        $sql = is_string($input['sql'] ?? null) ? trim($input['sql']) : '';
        $params = is_array($input['parameters'] ?? null) ? $input['parameters'] : [];
        $query = ['sql' => $sql, 'parameters' => $params];

        if ($sql === '') {
            return ToolResult::invalidInput($input, 'sql zorunlu bir metin.');
        }
        if (array_filter($params, fn ($v) => ! is_scalar($v) && $v !== null) !== []) {
            return ToolResult::invalidInput($input, 'parameters değerleri metin, sayı veya null olmalı.');
        }
        if (! $this->warehouse->enabled()) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, 'Veri ambarı bu ortamda tanımlı değil.', AuditLog::RESULT_FAILED);
        }

        // Süre sınırı ipucu yazılmadıysa eklenir.
        $timeout = (int) config('ai-data.warehouse_timeout_ms');
        if (! preg_match('/MAX_EXECUTION_TIME/i', $sql)) {
            $sql = (string) preg_replace('/\bSELECT\b/i', "SELECT /*+ MAX_EXECUTION_TIME({$timeout}) */", $sql, 1);
        }

        $errors = $this->warehouse->aiGuard()->check($sql, requireLimit: true)['errors'];
        $errors = [...$errors, ...$this->personalTableErrors($sql)];

        if ($errors !== []) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, 'Sorgu çalıştırılmadı: '.implode(' ', array_unique($errors)));
        }

        $plan = $this->warehouse->explain($sql);
        if ($plan === null) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, 'Veri ambarına şu an ulaşılamıyor.', AuditLog::RESULT_FAILED);
        }
        if (! $plan['ok']) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, 'Sorgu geçersiz: '.$plan['error'], AuditLog::RESULT_FAILED);
        }
        if ($plan['warnings'] !== []) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, 'Sorgu çalıştırılmadı, çok yavaş olur: '.implode(' ', $plan['warnings']).' İndeksli tarih sütunuyla filtrele.');
        }

        try {
            $rows = $this->warehouse->select($sql, $params);
        } catch (RuntimeException $e) {
            return $this->refuse('query_warehouse', 'IBS', $query, $purpose, $e->getMessage(), AuditLog::RESULT_FAILED);
        }

        return $this->answer('query_warehouse', 'IBS', $query, $purpose, $queryId, $rows === [] ? [] : array_keys($rows[0]), $rows, null);
    }

    /**
     * Yolcu düzeyindeki tablolar sadece sayım sorgularında: COUNT/SUM/AVG olmalı, * seçilemez, MIN/MAX olmaz,
     * GROUP BY kişi kimliği veya adı içeremez.
     *
     * @return list<string>
     */
    private function personalTableErrors(string $sql): array
    {
        $code = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $sql);
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([\w.]+)`?/i', $code, $m);

        $blocked = array_values(array_filter($m[1], fn (string $table) => collect(config('warehouse.ai_blocked_tables'))->contains(fn (string $part) => str_contains(strtolower($table), $part))));

        if ($blocked === []) {
            return [];
        }

        $label = implode(', ', array_unique($blocked));
        $errors = [];

        if (! preg_match('/\b(COUNT|SUM|AVG)\s*\(/i', $code)) {
            $errors[] = "{$label} yolcu düzeyinde veri içerir; sadece sayım (COUNT/SUM/AVG) sorgusunda kullanılabilir, satır döndüremez.";
        }
        if (preg_match('/\bSELECT\s+(?:\/\*.*?\*\/\s*)?(?:DISTINCT\s+)?(?:\w+\.)?\*/is', $code)) {
            $errors[] = "{$label} için * seçilemez.";
        }
        if (preg_match('/\b(MIN|MAX|GROUP_CONCAT|ANY_VALUE)\s*\(/i', $code)) {
            $errors[] = "{$label} ile MIN, MAX, GROUP_CONCAT kullanılamaz (tek kişinin değerini döndürebilir).";
        }
        if (preg_match('/\bGROUP\s+BY\s+(.+?)(?:\bHAVING\b|\bORDER\b|\bLIMIT\b|$)/is', $code, $group) && preg_match(self::PERSON_KEYS, $group[1])) {
            $errors[] = "{$label} kişi kimliği veya adına göre gruplanamaz (sonuç kişi düzeyine iner).";
        }

        return $errors;
    }

    /** @param  array<string, mixed>  $input */
    private function queryApm(array $input, string $purpose, string $queryId): ToolResult
    {
        $path = is_string($input['path'] ?? null) ? trim($input['path']) : '';
        $method = strtoupper(is_string($input['method'] ?? null) ? $input['method'] : 'GET');
        $body = is_array($input['body'] ?? null) ? $input['body'] : null;

        if ($path === '' || ! str_starts_with($path, '/')) {
            return ToolResult::invalidInput($input, "path '/' ile başlayan bir metin olmalı, ör. /api/legs?dateFrom=...");
        }
        if (str_contains($path, '{{') || str_contains($path, '{%')) {
            return ToolResult::invalidInput($input, 'Şablon ({{ }}) kullanılamaz; tarihleri somut yaz.');
        }

        // Ekip üyeleri hiçbir zaman istenmez.
        $path = (string) preg_replace('/([?&])includeCrewMembers=[^&]*&?/i', '$1', $path);
        $path = rtrim($path, '?&');
        $query = ['method' => $method, 'path' => $path, 'body' => $body];

        $operation = $this->apm->enabled() ? $this->apm->operationFor($method, $this->apm->baseUrl().$path) : null;
        if ($operation === null || ! $this->shaper->supports($method, $operation['path'])) {
            return $this->refuse('query_apm', 'APM', $query, $purpose, "{$method} ".strtok($path, '?').' AI sorgusuna açık değil. Sorgulanabilen uçlar: '.implode(', ', array_keys(ApmResultShaper::OPERATIONS)).'.');
        }

        try {
            $result = $this->apm->read($method, $path, $body);
        } catch (RuntimeException $e) {
            return $this->refuse('query_apm', 'APM', $query, $purpose, $e->getMessage(), AuditLog::RESULT_FAILED);
        }

        $shaped = $this->shaper->shape($method, $result['operation_path'], $result['response']);

        return $this->answer('query_apm', 'APM', $query, $purpose, $queryId, $shaped['columns'], $shaped['rows'], $shaped['total_rows']);
    }

    /** @param  array<string, mixed>  $input */
    private function querySuccessFactors(array $input, string $purpose, string $queryId): ToolResult
    {
        $path = is_string($input['path'] ?? null) ? ltrim(trim($input['path']), '/') : '';
        $query = ['path' => $path];

        if ($path === '') {
            return ToolResult::invalidInput($input, 'path zorunlu bir metin.');
        }
        if (! $this->successFactors->enabled()) {
            return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, 'SuccessFactors bu ortamda tanımlı değil.', AuditLog::RESULT_FAILED);
        }
        if (SuccessFactors::mentionsNationalId($path) || preg_match('/(phone|address|birth|salary|paycomp|payrate|nationalid|emergency)/i', rawurldecode($path))) {
            return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, 'TC kimlik, telefon, adres, doğum tarihi ve maaş bilgisi sorgulanamaz.');
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\([^)\/]*\))?(\/\$count)?(\?|$)/', $path)) {
            return ToolResult::invalidInput($input, 'Yol bir varlık adıyla başlamalı, ör. EmpJob?$filter=... veya EmpJob/$count?$filter=...');
        }

        [$resource, $params] = array_pad(explode('?', $path, 2), 2, '');

        if (is_string($input['group_by'] ?? null) && trim($input['group_by']) !== '') {
            return $this->groupSuccessFactors($input, preg_replace('#/\$count$#', '', $resource), $params, trim($input['group_by']), $query, $purpose, $queryId);
        }

        if (str_ends_with($resource, '/$count')) {
            $preview = $this->successFactors->preview($path);
            if (! $preview['ok']) {
                return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, 'SuccessFactors hatası: '.$preview['error'], AuditLog::RESULT_FAILED);
            }

            return $this->answer('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, $queryId, ['count'], [['count' => $preview['count']]], 1);
        }

        $max = (int) config('ai-data.warehouse_max_limit');
        $kept = array_filter($params === '' ? [] : explode('&', $params), fn (string $p) => ! preg_match('/^\$(top|format|inlinecount)=/i', $p));

        try {
            $records = $this->successFactors->fetchAll($resource.'?'.implode('&', [...$kept, '$format=JSON', '$top='.$max]), $max);
        } catch (RuntimeException $e) {
            return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, $e->getMessage(), AuditLog::RESULT_FAILED);
        }

        $rows = array_map(self::employeeRow(...), array_slice($records, 0, $max));
        $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows ?: [[]]))));
        $rows = array_map(fn (array $row) => array_merge(array_fill_keys($columns, null), $row), $rows);

        return $this->answer('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, $queryId, $columns, $rows, count($records));
    }

    /**
     * Gruplanmış sayım: filtreye uyan kayıtlardan sadece gruplanacak alan çekilir (sayfa sayfa), Laravel'de sayılır;
     * Claude'a sadece grup ve sayı gider. Kişisel alanlara göre gruplanamaz.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $query
     */
    private function groupSuccessFactors(array $input, string $resource, string $params, string $field, array $query, string $purpose, string $queryId): ToolResult
    {
        $query['group_by'] = $field;

        if (! preg_match('#^[A-Za-z_][A-Za-z0-9_]*(/[A-Za-z_][A-Za-z0-9_]*)?$#', $field)) {
            return ToolResult::invalidInput($input, 'group_by bir alan adı olmalı, ör. department veya departmentNav/name.');
        }
        if (preg_match('/(phone|address|birth|salary|paycomp|payrate|nationalid|emergency|email|firstname|lastname|userid|personid)/i', $field)) {
            return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, 'Kişisel bir alana göre gruplanamaz; departman, lokasyon, pozisyon, şirket gibi alanlar kullan.');
        }

        $kept = array_filter($params === '' ? [] : explode('&', $params), fn (string $p) => ! preg_match('/^\$(top|format|inlinecount|select|expand|skip|orderby)=/i', $p));
        $select = ['$select='.$field];
        if (str_contains($field, '/')) {
            $select[] = '$expand='.strstr($field, '/', true);
        }
        $max = (int) config('ai-data.successfactors_group_max_records', 20000);

        try {
            $records = $this->successFactors->fetchAll($resource.'?'.implode('&', [...$kept, ...$select, '$format=JSON', '$top=1000']), $max);
        } catch (RuntimeException $e) {
            return $this->refuse('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, $e->getMessage(), AuditLog::RESULT_FAILED);
        }

        $key = str_replace('/', '.', $field);
        $counts = [];
        foreach ($records as $record) {
            $value = self::employeeRow($record)[$key] ?? null;
            $label = $value === null || $value === '' ? '(boş)' : (string) $value;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        $rows = array_map(fn (string $group, int $count) => [$field => $group, 'count' => $count], array_keys($counts), array_values($counts));

        $result = $this->answer('query_successfactors', AiDataGate::SOURCE_SUCCESSFACTORS, $query, $purpose, $queryId, [$field, 'count'], $rows, count($rows));
        $data = json_decode($result->content, true);

        return is_array($data) ? $result->withData($data + ['records_counted' => count($records), 'capped' => count($records) >= $max]) : $result;
    }

    /**
     * SuccessFactors kaydını düzleştirir ve sadece izinli alanları tutar. Tarihler YYYY-AA-GG, e-posta kısmen maskeli.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function employeeRow(array $record, string $prefix = ''): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $nested = array_is_list($value) ? ($value[0] ?? null) : ($value['results'][0] ?? $value);
                if (is_array($nested)) {
                    $row += self::employeeRow($nested, $name);
                }

                continue;
            }

            if (! in_array(strtolower((string) $key), self::SF_ALLOWED_FIELDS, true)) {
                continue;
            }

            if (is_string($value) && preg_match('#^/Date\((-?\d+)(?:[+-]\d+)?\)/$#', $value, $date)) {
                $value = gmdate('Y-m-d', intdiv((int) $date[1], 1000));
            }

            if (strtolower((string) $key) === 'email' && is_string($value) && str_contains($value, '@')) {
                [$local, $domain] = explode('@', $value, 2);
                $value = mb_substr($local, 0, 1).'***@'.$domain;
            }

            $row[$name] = $value;
        }

        return $row;
    }

    /**
     * Sonucu AiDataGate'ten geçirir, denetim kaydına yazar ve Claude'a döner.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function answer(string $tool, string $source, array $query, string $purpose, string $queryId, array $columns, array $rows, ?int $totalRows): ToolResult
    {
        $gated = $this->gate->table($source, $columns, $rows, $totalRows);

        Audit::record('ai.query', meta: [
            'tool' => $tool,
            'query_id' => $queryId,
            'purpose' => $purpose,
            'query' => $query,
            'rows_returned' => $gated['returned_rows'],
            'total_rows' => $gated['total_rows'],
            'truncated' => $gated['truncated'],
            'masked_fields' => $gated['masked_fields'],
            'masked_values' => $gated['masked_values'],
        ], system: $source);

        return ToolResult::json([
            'source' => $source,
            'query_id' => $queryId,
            'columns' => $gated['columns'],
            'rows' => $gated['rows'],
            'total_rows' => $gated['total_rows'],
            'truncated' => $gated['truncated'],
            'masked_fields' => $gated['masked_fields'],
            'note' => $gated['truncated']
                ? "Toplam {$gated['total_rows']} satırın ilk {$gated['returned_rows']} satırı gösterildi. Tümü için sorguyu daralt veya toplulaştır (GROUP BY)."
                : 'Cevabında kaynağı belirt; sadece bu satırlara dayan.',
        ])->withMeta([
            'tool' => $tool,
            'source' => $source,
            'purpose' => $purpose,
            'query_id' => $queryId,
            'rows' => $gated['returned_rows'],
            'total_rows' => $gated['total_rows'],
            'masked' => $gated['masked_values'] > 0,
        ]);
    }

    /** @param  array<string, mixed>  $query */
    private function refuse(string $tool, string $source, array $query, string $purpose, string $message, string $result = AuditLog::RESULT_BLOCKED): ToolResult
    {
        Audit::record('ai.query', meta: ['tool' => $tool, 'purpose' => $purpose, 'query' => $query, 'error' => mb_substr($message, 0, 500)], system: $source, result: $result);

        return ToolResult::error($message)->withMeta(['tool' => $tool, 'source' => $source, 'purpose' => $purpose, 'error' => true, 'message' => mb_substr($message, 0, 300)]);
    }
}
