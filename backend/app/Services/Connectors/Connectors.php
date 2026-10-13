<?php

namespace App\Services\Connectors;

use App\Models\ConnectorCheck;
use App\Services\Apm\ApmApi;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\Warehouse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Bağlı sistemlerin durumu. Durum son kontrolden okunur (connectors:check); okuma sırasında dış sisteme istek atılmaz.
 *
 * connected: yapılandırılmış ve son 15 dakikada kontrol başarılı. attention: yapılandırılmış ama son kontrol başarısız,
 * eski veya yapılamıyor. not_configured: .env'de bilgisi yok.
 */
class Connectors
{
    public const CONNECTED = 'connected';

    public const ATTENTION = 'attention';

    public const NOT_CONFIGURED = 'not_configured';

    /** Yönetici şirket genelinde kapattı (A4.1). */
    public const DISABLED = 'disabled';

    public function __construct(
        private readonly Warehouse $warehouse,
        private readonly ApmApi $apm,
        private readonly SuccessFactors $successFactors,
    ) {}

    public function isConfigured(string $key): bool
    {
        return match ($key) {
            'ibs' => $this->warehouse->enabled(),
            'apm' => $this->apm->isConfigured(),
            'successfactors' => $this->successFactors->enabled(),
            'teams' => config('agents.teams_channels') !== [] || in_array('TEAMS_WEBHOOK_URL', (array) config('kestra.secrets'), true),
            // log/array sürücüsünde e-posta gönderilmez (yerel geliştirme): kurulum gerekli sayılır.
            'email' => ! in_array(config('mail.default'), ['log', 'array', null, ''], true),
            default => false,
        };
    }

    /** Laravel'den canlı kontrol edilebilen kaynaklar. Teams webhook'u mesaj göndermeden denenemez. */
    public function isCheckable(string $key): bool
    {
        return in_array($key, ['ibs', 'apm', 'successfactors'], true);
    }

    /**
     * Yapılandırılmış ve kontrol edilebilen kaynakları dener, sonucu kaydeder.
     *
     * @return list<ConnectorCheck>
     */
    public function checkAll(): array
    {
        $checks = [];
        foreach (array_keys(config('connectors.sources')) as $key) {
            if (($check = $this->check($key)) !== null) {
                $checks[] = $check;
            }
        }

        return $checks;
    }

    /** Tek bağlantıyı dener ve kaydeder; yapılandırılmamış veya canlı kontrol edilemiyorsa null. */
    public function check(string $key): ?ConnectorCheck
    {
        if (! $this->isConfigured($key) || ! $this->isCheckable($key)) {
            return null;
        }

        $startedAt = hrtime(true);
        $error = null;
        try {
            match ($key) {
                'ibs' => $this->warehouse->ping(),
                'apm' => $this->apm->ping(),
                'successfactors' => $this->successFactors->ping(),
            };
        } catch (Throwable $e) {
            $error = mb_substr(Secrets::scrub(class_basename($e).': '.$e->getMessage()), 0, 250);
        }

        return ConnectorCheck::create([
            'connector' => $key,
            'ok' => $error === null,
            'error' => $error,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'checked_at' => now(),
        ]);
    }

    /**
     * Home çipleri ve kaynak seçici (sadece chip = true olanlar).
     *
     * @return list<array{key: string, label: string, abbr: string, status: string, detail: string|null}>
     */
    public function status(): array
    {
        return array_values(array_filter($this->all(), fn (array $c) => config('connectors.sources')[$c['key']]['chip'] ?? false));
    }

    /**
     * Bütün bağlantıların durumu (Bağlantılar ekranı).
     *
     * @return list<array{key: string, label: string, abbr: string, status: string, detail: string|null}>
     */
    public function all(): array
    {
        $latest = ConnectorCheck::query()
            ->whereIn('id', ConnectorCheck::query()->selectRaw('max(id)')->groupBy('connector'))
            ->get()
            ->keyBy('connector');
        $lastOk = ConnectorCheck::query()->where('ok', true)->selectRaw('connector, max(checked_at) as at')->groupBy('connector')->pluck('at', 'connector');

        $list = [];
        foreach (config('connectors.sources') as $key => $source) {
            [$status, $detail] = $this->resolve($key, $latest->get($key), $lastOk->get($key));
            $list[] = ['key' => $key, 'label' => $source['label'], 'abbr' => $source['abbr'], 'status' => $status, 'detail' => $detail];
        }

        return $list;
    }

    /** @return array{0: string, 1: string|null} */
    private function resolve(string $key, ?ConnectorCheck $latest, mixed $lastOkAt): array
    {
        if (app(ConnectorRegistry::class)->isDisabled($key)) {
            return [self::DISABLED, 'Yönetici tarafından şirket genelinde kapatıldı.'];
        }

        if ($key === 'email' && in_array(config('mail.default'), ['log', 'array'], true)) {
            return [self::NOT_CONFIGURED, 'E-postalar gönderilmiyor; MAIL_MAILER='.config('mail.default').' (yerel geliştirme).'];
        }

        if (! $this->isConfigured($key)) {
            return [self::NOT_CONFIGURED, null];
        }

        if (! $this->isCheckable($key)) {
            return [self::ATTENTION, 'Yapılandırıldı; mesaj göndermeden canlı kontrol yapılamıyor.'];
        }

        if ($latest === null) {
            return [self::ATTENTION, 'Henüz kontrol edilmedi.'];
        }

        $fresh = $latest->checked_at->gt(now()->subMinutes((int) config('connectors.fresh_minutes')));

        if ($latest->ok && $fresh) {
            return [self::CONNECTED, null];
        }

        if (! $latest->ok) {
            return [self::ATTENTION, $lastOkAt === null ? 'Son kontrol başarısız.' : 'Son başarılı kontrol '.self::ago(Carbon::parse($lastOkAt)).'.'];
        }

        return [self::ATTENTION, 'Son kontrol '.self::ago($latest->checked_at).'.'];
    }

    private static function ago(CarbonInterface $at): string
    {
        return $at->locale('tr')->diffForHumans();
    }
}
