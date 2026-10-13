<?php

namespace App\Ai;

use App\Models\User;
use App\Services\Claude\ToolResult;
use Closure;

/**
 * Bir cevabın canlı izi: gerçekte olan adımlar (model seçimi, izin kapsamı, sorgular, diğer araçlar, soru, hata).
 * Her adım `step` olayıyla akar ve cevap mesajının meta'sına (answer.trace) yazılır. Sorgu metni sadece admin ve
 * builder'a gönderilir; kayıtta tutulur, MessageResource görüntüleyene göre süzer.
 */
class AnswerTrace
{
    /** @var list<array<string, mixed>> */
    private array $steps = [];

    private int $startedAt;

    /** @var list<array<string, mixed>> Bu cevapta çalışan veri sorguları (kaynak numaraları için) */
    private array $queries = [];

    /** @var list<string> */
    private array $models = [];

    private ?string $lastModel = null;

    /**
     * @param  Closure(string $event, mixed $data): void  $emit
     */
    public function __construct(private readonly Closure $emit, private readonly ?User $viewer)
    {
        $this->startedAt = hrtime(true);
    }

    /**
     * Soru kartından sonra devam eden cevap: sorudan önceki adımlar ve sorgular bu cevabın parçasıdır (kaynak
     * numaraları onlara da verilebilir).
     *
     * @param  array<string, mixed>  $previous  Soru mesajındaki answer özeti
     */
    public function resume(array $previous): void
    {
        $this->queries = $previous['queries'] ?? [];
        $this->models = $previous['models'] ?? [];
        $this->lastModel = $this->models === [] ? null : end($this->models);
        $this->startedAt -= (int) ($previous['duration_ms'] ?? 0) * 1_000_000;

        foreach ($previous['trace'] ?? [] as $step) {
            if ($step['kind'] === 'ask') {
                $step['status'] = 'done';
                $step['label'] = 'Cevabın alındı';
            }
            $this->steps[] = $step;
            ($this->emit)('step', self::forViewer($step, $this->viewer));
        }
    }

    /**
     * @param  list<array{key: string, label: string, in_scope: bool}>  $sources
     */
    public function scope(array $sources): void
    {
        $data = array_values(array_filter($sources, fn (array $s) => $s['configured'] ?? true));
        $inScope = count(array_filter($data, fn (array $s) => $s['in_scope']));
        $who = $this->viewer === null ? 'Sistem' : $this->viewer->name.' · '.ucfirst($this->viewer->role);

        $this->add([
            'kind' => 'scope',
            'status' => 'done',
            'label' => 'İzin kapsamı',
            'detail' => count($data) === 0 ? "{$who} — bağlı veri kaynağı yok" : "{$who} — ".count($data)." bağlı kaynaktan {$inScope}'i kapsamda",
            'sources' => array_map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'in_scope' => $s['in_scope']], $sources),
        ]);
    }

    /** Model seçimi: ilk turda ve model değişince bir adım. */
    public function model(string $model, string $label, bool $auto, string $reason): void
    {
        if ($model === $this->lastModel) {
            return;
        }

        $this->lastModel = $model;
        $this->models[] = $model;
        $this->add([
            'kind' => 'model',
            'status' => 'done',
            'label' => 'Model seçildi · '.($auto ? "Auto → {$label}" : $label),
            'detail' => $reason,
            'model' => $model,
            'auto' => $auto,
        ]);
    }

    /** @param  array<string, mixed>  $input */
    public function toolStarted(string $id, string $tool, array $input): void
    {
        $isQuery = in_array($tool, DataQueryTools::TOOLS, true);

        $this->add([
            'id' => $id,
            'kind' => $isQuery ? 'query' : 'tool',
            'status' => 'running',
            'tool' => $tool,
            'purpose' => $isQuery && is_string($input['purpose'] ?? null) ? mb_substr($input['purpose'], 0, 200) : null,
            // Sorgu metni (SQL ve parametreler veya istek yolu): arayüzde sadece admin ve builder görür.
            'query' => $isQuery ? array_intersect_key($input, array_flip(['sql', 'parameters', 'method', 'path', 'body'])) : null,
        ]);
    }

    public function toolFinished(string $id, ToolResult $result): void
    {
        $meta = $result->meta ?? [];
        $error = $result->isError ? ($meta['message'] ?? self::errorText($result)) : null;

        if (! $result->isError && isset($meta['query_id'])) {
            $step = collect($this->steps)->firstWhere('id', $id);
            $this->queries[] = $meta + ['query' => $step['query'] ?? null, 'at' => now()->toIso8601String()];
        }

        $this->update($id, array_filter([
            'status' => $result->isError ? 'error' : 'done',
            'source' => $meta['source'] ?? null,
            'rows' => $meta['rows'] ?? null,
            'total_rows' => $meta['total_rows'] ?? null,
            'masked' => $meta['masked'] ?? null,
            'query_id' => $meta['query_id'] ?? null,
            'error' => $error,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    public function waitingForUser(array $questions): void
    {
        $this->add([
            'kind' => 'ask',
            'status' => 'waiting',
            'label' => 'Bekliyor · Cevabın gerekiyor',
            'detail' => $questions[0]['context'] ?? $questions[0]['question'] ?? null,
        ]);
    }

    public function failed(string $message): void
    {
        $this->add(['kind' => 'error', 'status' => 'error', 'label' => 'Hata', 'detail' => $message]);
    }

    /** @return list<array<string, mixed>> */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Cevap mesajına yazılacak özet.
     *
     * @param  list<string>  $citationMismatch
     * @return array<string, mixed>
     */
    public function summary(array $citationMismatch = []): array
    {
        return [
            'trace' => $this->steps,
            'queries' => $this->queries,
            'models' => $this->models,
            'auto' => (bool) (collect($this->steps)->firstWhere('kind', 'model')['auto'] ?? false),
            'duration_ms' => (int) ((hrtime(true) - $this->startedAt) / 1_000_000),
            'steps' => count($this->steps),
            // Cevap sırasında dış sisteme yazan araç yok (yazma Faz A3'te onayla gelir).
            'writes' => [],
            'citation_mismatch' => $citationMismatch,
        ];
    }

    /** Görüntüleyenin rolüne göre adım: sorgu metni sadece admin ve builder'a. */
    public static function forViewer(array $step, ?User $viewer): array
    {
        return $viewer?->canBuild() ? $step : array_diff_key($step, ['query' => true]);
    }

    /** @param  array<string, mixed>  $step */
    private function add(array $step): void
    {
        $step['id'] ??= 's'.(count($this->steps) + 1);
        $step['at_ms'] = (int) ((hrtime(true) - $this->startedAt) / 1_000_000);
        $this->steps[] = $step;
        ($this->emit)('step', self::forViewer($step, $this->viewer));
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $id, array $changes): void
    {
        foreach ($this->steps as $i => $step) {
            if ($step['id'] === $id) {
                $this->steps[$i] = array_merge($step, $changes);
                ($this->emit)('step', self::forViewer($this->steps[$i], $this->viewer));

                return;
            }
        }
    }

    private static function errorText(ToolResult $result): string
    {
        $data = json_decode($result->content, true);

        return mb_substr(is_array($data) ? (string) ($data['error'] ?? $data['INVALID_INPUT'] ?? 'Araç hata verdi.') : 'Araç hata verdi.', 0, 300);
    }
}
