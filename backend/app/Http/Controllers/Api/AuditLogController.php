<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\Audit;
use App\Services\Connectors\Secrets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Yönetişim > Denetim kaydı (A4.4, sadece admin): filtre, imleçle sayfalama, CSV. Kayıt sadece okunur; güncelleme
 * veya silme ucu yok (model de engeller). Ayrıntıdaki gizli değerler temizlenir; sorgu metni (SQL/yol) sadece
 * admin ve builder'a gösterilir.
 */
class AuditLogController extends Controller
{
    private const PAGE = 50;

    private const CSV_LIMIT = 50000;

    private const TZ = 'Europe/Istanbul';

    /** Sorgu metni taşıyan meta alanları (A2 kuralı: sadece admin/builder). */
    private const QUERY_KEYS = ['query', 'sql', 'path'];

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'actor' => ['nullable', Rule::in([AuditLog::ACTOR_USER, AuditLog::ACTOR_AGENT, AuditLog::ACTOR_SYSTEM])],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100', 'regex:/^[a-z_.]+\*?$/'],
            'result' => ['nullable', Rule::in([AuditLog::RESULT_ALLOWED, AuditLog::RESULT_BLOCKED, AuditLog::RESULT_AWAITING_APPROVAL, AuditLog::RESULT_FAILED])],
            'q' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
            'cursor' => ['nullable', 'string'],
        ]);
        $viewer = $request->user();

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv($filters, $viewer);
        }

        $page = $this->query($filters)->orderByDesc('id')->cursorPaginate(self::PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log) => $this->present($log, $viewer))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'options' => [
                'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
                'users' => User::query()->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, self::TZ)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', CarbonImmutable::parse($to, self::TZ)->startOfDay()->addDay()->utc()))
            ->when($filters['actor'] ?? null, fn ($q, $actor) => $q->where('actor_type', $actor))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => str_ends_with($action, '*')
                ? $q->whereRaw("action like ? escape '!'", [self::like(rtrim($action, '*')).'%'])
                : $q->where('action', $action))
            ->when($filters['result'] ?? null, fn ($q, $result) => $q->where('result', $result))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Eski kayıtlarda ayrıntı \u kaçışlı JSON; ikisi de aranır.
                $terms = array_unique(['%'.self::like($term).'%', '%'.self::like(trim((string) json_encode($term), '"')).'%']);
                $q->where(function ($w) use ($terms) {
                    foreach (['action', 'actor_label', 'subject_type', 'subject_id', 'system', 'meta'] as $column) {
                        foreach ($terms as $like) {
                            $w->orWhereRaw("{$column} like ? escape '!'", [$like]);
                        }
                    }
                });
            });
    }

    /** LIKE için kaçış ('!' kaçış karakteri; SQLite ve MySQL'de aynı). */
    private static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @return array<string, mixed> */
    private function present(AuditLog $log, User $viewer): array
    {
        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'actor_type' => $log->actor_type,
            'actor' => $log->actor_label ?? ($log->actor_type === AuditLog::ACTOR_SYSTEM ? 'Sistem' : null),
            'action' => $log->action,
            'subject' => $log->subject_type === null ? null : class_basename($log->subject_type).($log->subject_id !== null ? " #{$log->subject_id}" : ''),
            'system' => $log->system,
            'result' => $log->result,
            'cost_usd' => $log->cost_usd === null ? null : (float) $log->cost_usd,
            'meta' => $this->meta($log->meta, $viewer),
        ];
    }

    /**
     * Gizli değerler temizlenir; sorgu metni sadece admin/builder'a.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null
     */
    private function meta(?array $meta, User $viewer): ?array
    {
        if ($meta === null) {
            return null;
        }
        if (! $viewer->canBuild()) {
            $meta = self::without($meta, self::QUERY_KEYS);
        }
        $json = Secrets::scrub((string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return json_decode((string) $json, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function without(array $data, array $keys): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::without($value, $keys);
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $filters */
    private function csv(array $filters, User $viewer): StreamedResponse
    {
        $logs = $this->query($filters)->orderByDesc('id')->limit(self::CSV_LIMIT)->get();
        Audit::record('audit.export', null, meta: ['filters' => array_diff_key($filters, ['format' => 1, 'cursor' => 1]), 'rows' => $logs->count()], user: $viewer);

        return response()->streamDownload(function () use ($logs, $viewer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Zaman (UTC)', 'Aktör türü', 'Aktör', 'Olay', 'Hedef', 'Sistem', 'Sonuç', 'Maliyet (USD)', 'Ayrıntı']);
            foreach ($logs as $log) {
                $row = $this->present($log, $viewer);
                fputcsv($out, [$row['at'], $row['actor_type'], $row['actor'], $row['action'], $row['subject'], $row['system'], $row['result'], $row['cost_usd'], $row['meta'] === null ? '' : json_encode($row['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            fclose($out);
        }, 'fortis-denetim-kaydi.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
