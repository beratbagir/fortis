<?php

namespace App\Http\Controllers\Api;

use App\Ai\ApmResultShaper;
use App\Http\Controllers\Controller;
use App\Models\AgentAction;
use App\Models\AuditLog;
use App\Models\ConnectorCheck;
use App\Services\Auth\Audit;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Connectors;
use App\Services\Connectors\Secrets;
use App\Services\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Bağlantılar (A4.1). Durum son kontrolden okunur; dış sisteme istek sadece admin'in "Şimdi kontrol et"inde atılır.
 * Gizli bilgi hiçbir yanıtta yer almaz: .env için sadece değişken adı ve tanımlı mı (admin), hatalar temizlenmiş.
 * Herkes görür; kapatma/açma ve kontrol sadece admin.
 */
class ConnectorController extends Controller
{
    public function __construct(
        private readonly Connectors $connectors,
        private readonly ConnectorRegistry $registry,
    ) {}

    /** Home çipleri ve kaynak seçici. */
    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->connectors->status()]);
    }

    public function index(): JsonResponse
    {
        $rows = $this->registry->sync();
        $statuses = collect($this->connectors->all())->keyBy('key');
        $lastChecks = ConnectorCheck::query()->selectRaw('connector, max(checked_at) as at')->groupBy('connector')->pluck('at', 'connector');

        $list = collect(ConnectorRegistry::catalog())->map(fn (array $source, string $key) => $this->card($key, $source, $statuses[$key], $rows[$key]->enabled, $lastChecks[$key] ?? null))->values();

        return response()->json([
            'data' => $list,
            'summary' => [
                'connected' => $list->where('status', Connectors::CONNECTED)->count(),
                'attention' => $list->where('status', Connectors::ATTENTION)->count(),
                'needs_setup' => $list->where('status', Connectors::NOT_CONFIGURED)->count(),
                'disabled' => $list->where('status', Connectors::DISABLED)->count(),
            ],
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $source = ConnectorRegistry::catalog()[$key] ?? abort(404);
        $row = $this->registry->sync()[$key];
        $user = $request->user();
        $status = collect($this->connectors->all())->firstWhere('key', $key);
        $checks = ConnectorCheck::query()->where('connector', $key)->where('checked_at', '>=', now()->subDay())->orderBy('checked_at')->get();
        $lastError = ConnectorCheck::query()->where('connector', $key)->where('ok', false)->latest('checked_at')->first();
        $catalog = config('agents.tools');
        $tools = ConnectorRegistry::tools($key);

        return response()->json(['data' => $this->card($key, $source, $status, $row->enabled, $checks->last()?->checked_at) + [
            'disabled' => $row->enabled ? null : ['reason' => $row->disabled_reason, 'by' => $row->disabledBy?->name, 'at' => $row->disabled_at?->toIso8601String()],
            'checks' => $checks->map(fn (ConnectorCheck $c) => ['ok' => $c->ok, 'at' => $c->checked_at->toIso8601String(), 'duration_ms' => $c->duration_ms])->values(),
            'avg_duration_ms' => $checks->isEmpty() ? null : (int) round($checks->avg('duration_ms')),
            'last_error' => $lastError ? ['message' => Secrets::scrub($lastError->error), 'at' => $lastError->checked_at->toIso8601String()] : null,
            'checkable' => $this->connectors->isCheckable($key) && $this->connectors->isConfigured($key),
            'tools' => array_map(fn (string $t) => [
                'name' => $t,
                'kind' => $catalog[$t]['kind'] ?? 'read',
                'label' => $catalog[$t]['label'] ?? $t,
                'description' => $catalog[$t]['description'] ?? (config('connectors.tool_descriptions')[$t] ?? ''),
            ], $tools),
            'used_by' => $this->registry->agentsUsing($key)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'owner' => $a->owner?->name])->values(),
            'chat_queries_7d' => $tools === [] ? 0 : AuditLog::query()->where('action', 'ai.query')->where('actor_type', '!=', AuditLog::ACTOR_AGENT)->where('created_at', '>=', now()->subDays(7))->whereIn('meta->tool', $tools)->count(),
            'protection' => $this->protection($key),
            'needs' => $source['needs'] ?? [],
            // .env: sadece ad ve tanımlı mı, sadece admin. Değer asla.
            'env' => $user->isAdmin() ? collect($source['env'] ?? [])->map(fn (string $configKey, string $name) => ['name' => $name, 'defined' => filled(config($configKey))])->values() : null,
            'affected_agents' => $this->registry->agentsUsing($key)->count(),
        ]]);
    }

    public function check(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ConnectorRegistry::catalog()[$key]), 404);
        abort_unless($this->connectors->isConfigured($key) && $this->connectors->isCheckable($key), 422, 'Bu bağlantı canlı kontrol edilemiyor (yapılandırılmamış veya kontrol ucu yok).');

        $limiterKey = "connector-check:{$key}";
        if (RateLimiter::tooManyAttempts($limiterKey, 1)) {
            return response()->json(['message' => 'Bu bağlantı az önce kontrol edildi; '.RateLimiter::availableIn($limiterKey).' sn sonra tekrar dene.'], 429);
        }
        RateLimiter::hit($limiterKey, (int) config('connectors.manual_check_seconds'));

        $check = $this->connectors->check($key);
        Audit::record('connector.check', null, $check?->ok ? AuditLog::RESULT_ALLOWED : AuditLog::RESULT_FAILED, ['connector' => $key, 'ok' => $check?->ok, 'duration_ms' => $check?->duration_ms], user: $request->user());

        return $this->show($request, $key);
    }

    public function disable(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ConnectorRegistry::catalog()[$key]), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->registry->disable($key, trim($validated['reason']), $request->user());

        return $this->show($request, $key);
    }

    public function enable(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ConnectorRegistry::catalog()[$key]), 404);
        $this->registry->enable($key, $request->user());

        return $this->show($request, $key);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array{status: string, detail: string|null}  $status
     * @return array<string, mixed>
     */
    private function card(string $key, array $source, array $status, bool $enabled, mixed $lastCheckedAt): array
    {
        $tools = ConnectorRegistry::tools($key);
        $weekStart = CarbonImmutable::now('Europe/Istanbul')->startOfWeek()->utc();

        return [
            'key' => $key,
            'label' => $source['label'],
            'abbr' => $source['abbr'],
            'category' => $source['category'],
            'description' => $source['description'] ?? null,
            'status' => $status['status'],
            'detail' => $status['detail'] ?? null,
            'enabled' => $enabled,
            'scopes' => ConnectorRegistry::scopes($key),
            'last_checked_at' => $lastCheckedAt ? CarbonImmutable::parse($lastCheckedAt)->toIso8601String() : null,
            // Bu hafta: sohbet ve agent veri sorguları + gönderilen aksiyonlar.
            'uses_this_week' => $tools === [] ? 0
                : AuditLog::query()->where('action', 'ai.query')->where('created_at', '>=', $weekStart)->whereIn('meta->tool', $tools)->count()
                    + AgentAction::query()->whereIn('type', $tools)->where('status', AgentAction::EXECUTED)->where('executed_at', '>=', $weekStart)->count(),
        ];
    }

    /**
     * Veri koruması: sadece kategori adları ve kurallar; değer yok.
     *
     * @return array{masked_groups: list<string>, rules: list<string>}
     */
    private function protection(string $key): array
    {
        $category = ConnectorRegistry::catalog()[$key]['category'];
        if ($category !== 'data') {
            return ['masked_groups' => [], 'rules' => $category === 'channel'
                ? ['Gönderimler sadece izinli alıcılara/kanallara ve onaydan sonra.', 'Şirket dışı alıcı '.(app(AppSettings::class)->allowExternalRecipients() ? 'onaylı.' : 'kapalı.')]
                : []];
        }

        $rules = match ($key) {
            'ibs' => [
                'Sadece SELECT; LIMIT zorunlu, en fazla '.config('warehouse.max_limit').' satır.',
                'Yolcu düzeyindeki '.count(config('warehouse.ai_blocked_tables')).' tablo sadece sayım/toplamla sorgulanabilir.',
            ],
            'apm' => [
                'Sadece '.count(ApmResultShaper::OPERATIONS).' okuma ucu sorgulanabilir; yanıt izinli alanlara indirilir.',
                'Mürettebat adları ve iletişim bilgileri hiç okunmaz.',
            ],
            'successfactors' => [
                'TC kimlik, telefon, adres, doğum tarihi ve maaş sorgulanamaz.',
                'Çalışan adı/soyadı '.(app(AppSettings::class)->allowEmployeeNames() ? 'gösterilebilir (ayar açık).' : 'maskelenir (ayar kapalı).'),
            ],
            default => [],
        };

        return [
            'masked_groups' => array_keys(config('ai-data.masked_groups')),
            'rules' => [...$rules, 'Claude\'a en fazla '.config('ai-data.max_rows', 200).' satır gider; kişisel veri içerik taramasıyla da maskelenir.'],
        ];
    }
}
