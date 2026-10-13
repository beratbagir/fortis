<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Connectors\Connectors;
use App\Services\Flows\RunsIndex;
use App\Services\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Home: karşılama, örnek sorular, kullanıcının onayını bekleyen çalışmalar ve bugünkü etkinlik. Sadece gerçek veri;
 * bekleyen iş yoksa liste boş döner.
 */
class HomeController extends Controller
{
    private const TIMEZONE = 'Europe/Istanbul';

    public function __invoke(Request $request, Connectors $connectors, AppSettings $settings, RunsIndex $runs): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Çalışma listesi yerel dizinden okunur; dizin en fazla 10 sn'de bir Kestra ile eşitlenir. Kestra'ya ulaşılamazsa son bilinen liste.
        try {
            $runs->sync();
        } catch (Throwable $e) {
            report($e);
        }

        $connected = collect($connectors->status())->reject(fn (array $c) => $c['status'] === Connectors::NOT_CONFIGURED)->pluck('key')->all();

        // Seni bekleyenler: Inbox'taki açık onaylar ve sorular.
        $waiting = InboxItem::query()->where('user_id', $user->id)->active()->whereIn('type', InboxItem::ACTION_TYPES)->latest('id');

        $todayStart = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->utc();

        return response()->json(['data' => [
            'greeting_name' => str($user->name)->before(' ')->value() ?: $user->name,
            'suggestions' => $this->suggestions($user, $connected, $settings->dataAccessEnabled()),
            'waiting_total' => (clone $waiting)->count(),
            'waiting' => $waiting->limit(3)->get()->map(fn (InboxItem $i) => [
                'id' => $i->id,
                'kind' => $i->type === InboxItem::QUESTION ? 'question' : 'approval',
                'title' => $i->title,
                'detail' => $i->body ?? $i->source_label ?? '',
                'since' => $i->created_at?->toIso8601String(),
            ])->values(),
            'activity' => Execution::query()
                ->with('flow')
                ->where('is_test', false)
                ->where('started_at', '>=', $todayStart)
                ->latest('started_at')
                ->limit(5)
                ->get()
                ->map(fn (Execution $e) => [
                    'id' => $e->kestra_execution_id,
                    'title' => $e->flow?->title ?? 'Akış',
                    'state' => $e->state,
                    'tone' => match ($e->state) {
                        'SUCCESS' => 'ok',
                        'FAILED', 'KILLED', 'PAUSED' => 'bad',
                        'WARNING' => 'warn',
                        default => 'running',
                    },
                    'detail' => self::stateText($e->state),
                    'time' => $e->started_at?->setTimezone(self::TIMEZONE)->format('H:i'),
                ])->values(),
        ]]);
    }

    /**
     * Departmana göre örnek sorular: sadece bağlı kaynaklarla cevaplanabilenler; veri erişimi kapalıyken veri soruları yok.
     *
     * @param  list<string>  $connected
     * @return list<array{type: string, text: string}>
     */
    private function suggestions(User $user, array $connected, bool $dataAccess): array
    {
        $all = config('home.suggestions');
        $list = $all[$user->department?->name ?? ''] ?? $all['default'];

        return collect([...$list, ...$all['default']])
            ->filter(fn (array $s) => array_diff($s['requires'] ?? [], $connected) === [])
            ->reject(fn (array $s) => $s['type'] === 'question' && ! $dataAccess)
            ->unique('text')
            ->take(4)
            ->map(fn (array $s) => ['type' => $s['type'], 'text' => $s['text']])
            ->values()
            ->all();
    }

    private static function stateText(string $state): string
    {
        return match ($state) {
            'SUCCESS' => 'Başarıyla tamamlandı',
            'FAILED' => 'Hata verdi',
            'KILLED' => 'Durduruldu',
            'WARNING' => 'Uyarıyla tamamlandı',
            'PAUSED' => 'Onay bekliyor',
            default => 'Çalışıyor',
        };
    }
}
