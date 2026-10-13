<?php

namespace App\Services\Flows\Blocks;

use App\Services\Flows\FlowEditException;

/**
 * Tuval işlemlerini (operations) ayrıştırılmış YAML'a uygular. Tuval YAML göndermez; işlem listesi gönderir.
 *
 * İşlemler: update_settings, insert, delete, move, update_trigger, insert_trigger, delete_trigger.
 * Görev id'leri sabittir; ayar düzenlemek id'yi değiştirmez. Yeni bloklar benzersiz id alır.
 * Gelişmiş bloklar düzenlenemez; silinebilir ve taşınabilir (arayüz silmeden önce onay ister).
 *
 * Sonuç her zaman FlowValidator'dan geçirilir (FlowService::recordEdit); bu sınıf güvenlik kontrolü yapmaz,
 * yalnızca yapıyı değiştirir.
 */
class FlowEditor
{
    public function __construct(private readonly BlockCatalog $blocks) {}

    /**
     * @param  array<string, mixed>  $flow
     * @param  list<array<string, mixed>>  $operations
     * @return array{flow: array<string, mixed>, created: list<string>}
     *
     * @throws FlowEditException
     */
    public function apply(array $flow, array $operations): array
    {
        $created = [];

        foreach ($operations as $i => $op) {
            $name = is_array($op) ? ($op['op'] ?? null) : null;

            [$flow, $new] = match ($name) {
                'update_settings' => [$this->updateSettings($flow, $op), []],
                'insert' => $this->insert($flow, $op),
                'delete' => [$this->delete($flow, $op), []],
                'move' => [$this->move($flow, $op), []],
                'update_trigger' => [$this->updateTrigger($flow, $op), []],
                'insert_trigger' => $this->insertTrigger($flow, $op),
                'delete_trigger' => [$this->deleteTrigger($flow, $op), []],
                default => throw new FlowEditException(["İşlem {$i}: bilinmeyen işlem."]),
            };
            $created = [...$created, ...$new];
        }

        return ['flow' => $flow, 'created' => $created];
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function updateSettings(array $flow, array $op): array
    {
        $id = self::string($op, 'task_id');
        $path = $this->findTask($flow, $id) ?? throw FlowEditException::forTask($id, "'{$id}' adında bir adım yok.");
        $task = self::get($flow, $path);
        $recognized = $this->blocks->recognize($task);

        if ($recognized['readonly']) {
            throw FlowEditException::forTask($id, 'Gelişmiş bloklar tuvalden düzenlenemez. YAML görünümünden değiştirin.');
        }

        $settings = array_replace($recognized['settings'], self::settings($op));

        return self::set($flow, $path, $this->blocks->toTask($recognized['kind'], $settings, $task));
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function insert(array $flow, array $op): array
    {
        $kind = self::string($op, 'kind');

        if (! $this->blocks->canInsert($kind) || $this->blocks->isTrigger($kind)) {
            throw new FlowEditException(["'{$kind}' bloğu eklenemez. Sadece paletteki izinli bloklar eklenebilir."]);
        }

        [$listPath, $index] = $this->position($flow, $op);
        $taken = $this->ids($flow);
        $created = [];

        // APM verisi aynı akıştaki token görevinin çıktısıyla okunur; yoksa önce token görevi eklenir.
        $context = [];
        if ($kind === 'data_apm') {
            $context['apm_token'] = $this->firstOfKind($flow, 'apm_token');

            if ($context['apm_token'] === null) {
                $tokenId = $this->blocks->uniqueId('apm_token', $taken);
                $taken[] = $tokenId;
                $flow = self::insertAt($flow, $listPath, $index++, $this->blocks->toTask('apm_token', [], null, ['id' => $tokenId]));
                $context['apm_token'] = $tokenId;
                $created[] = $tokenId;
            }
        }

        $id = $this->blocks->uniqueId($kind, $taken);
        $taken[] = $id;
        $task = $this->blocks->toTask($kind, self::settings($op), null, ['id' => $id] + $context);

        // Kestra boş döngü ve koşul kabul etmez: içine düzenlenecek bir Log adımı konur.
        foreach (array_slice($this->blocks->branches($kind), 0, 1) as $branch) {
            $placeholder = $this->blocks->uniqueId('log', $taken);
            $taken[] = $placeholder;
            $task[$branch] = [['id' => $placeholder, 'type' => 'io.kestra.plugin.core.log.Log', 'message' => 'Bu adımı değiştirin veya yeni adım ekleyin.']];
            $created[] = $placeholder;
        }

        $created[] = $id;

        return [self::insertAt($flow, $listPath, $index, $task), $created];
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function delete(array $flow, array $op): array
    {
        $id = self::string($op, 'task_id');
        $path = $this->findTask($flow, $id) ?? throw FlowEditException::forTask($id, "'{$id}' adında bir adım yok.");

        return self::remove($flow, $path);
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function move(array $flow, array $op): array
    {
        $id = self::string($op, 'task_id');
        $path = $this->findTask($flow, $id) ?? throw FlowEditException::forTask($id, "'{$id}' adında bir adım yok.");
        $task = self::get($flow, $path);

        $parent = $op['parent_id'] ?? null;
        if (is_string($parent) && ($parent === $id || in_array($parent, $this->ids($task), true))) {
            throw FlowEditException::forTask($id, 'Bir blok kendi içine taşınamaz.');
        }

        $flow = self::remove($flow, $path);
        [$listPath, $index] = $this->position($flow, $op);

        return self::insertAt($flow, $listPath, $index, $task);
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function updateTrigger(array $flow, array $op): array
    {
        $id = self::string($op, 'trigger_id');
        $index = $this->triggerIndex($flow, $id);
        $trigger = $flow['triggers'][$index];
        $recognized = $this->blocks->recognize($trigger, trigger: true);

        if ($recognized['readonly']) {
            throw FlowEditException::forTask($id, 'Bu tetikleyici tuvalden düzenlenemez. YAML görünümünden değiştirin.');
        }

        $flow['triggers'][$index] = $this->blocks->toTask($recognized['kind'], array_replace($recognized['settings'], self::settings($op)), $trigger);

        return $flow;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function insertTrigger(array $flow, array $op): array
    {
        $kind = self::string($op, 'kind');

        if (! $this->blocks->canInsert($kind) || ! $this->blocks->isTrigger($kind)) {
            throw new FlowEditException(["'{$kind}' tetikleyicisi eklenemez."]);
        }

        $id = $this->blocks->uniqueId($kind, $this->ids($flow));
        $flow['triggers'] = [...(is_array($flow['triggers'] ?? null) ? $flow['triggers'] : []), $this->blocks->toTask($kind, self::settings($op), null, ['id' => $id])];

        return [$flow, [$id]];
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function deleteTrigger(array $flow, array $op): array
    {
        $index = $this->triggerIndex($flow, self::string($op, 'trigger_id'));
        array_splice($flow['triggers'], $index, 1);

        if ($flow['triggers'] === []) {
            unset($flow['triggers']);
        }

        return $flow;
    }

    /**
     * Eklemenin yapılacağı liste ve sıra. parent_id null ise akışın kendi listesi (branch: tasks/errors/finally),
     * değilse konteyner bloğun dalı (Döngü: tasks, Koşul: then/else). after_id null ise listenin başı.
     *
     * @param  array<string, mixed>  $flow
     * @param  array<string, mixed>  $op
     * @return array{0: list<string|int>, 1: int}
     */
    private function position(array $flow, array $op): array
    {
        $parent = $op['parent_id'] ?? null;
        $branch = $op['branch'] ?? null;

        if ($parent === null) {
            $branch ??= 'tasks';
            if (! in_array($branch, FlowGraph::ROOT_LISTS, true)) {
                throw new FlowEditException(["Geçersiz liste: {$branch}."]);
            }
            $listPath = [$branch];
        } else {
            $parentPath = is_string($parent) ? $this->findTask($flow, $parent) : null;
            if ($parentPath === null) {
                throw new FlowEditException(["'{$parent}' adında bir konteyner blok yok."]);
            }

            $kind = $this->blocks->recognize(self::get($flow, $parentPath))['kind'];
            $branches = $this->blocks->branches($kind);
            $branch ??= $branches[0] ?? null;

            if (! in_array($branch, $branches, true)) {
                throw FlowEditException::forTask($parent, 'Bu bloğun içine adım eklenemez.');
            }
            $listPath = [...$parentPath, $branch];
        }

        $list = self::get($flow, $listPath) ?? [];
        $after = $op['after_id'] ?? null;
        $index = 0;

        if ($after !== null) {
            $found = array_search($after, array_map(fn ($t) => is_array($t) ? ($t['id'] ?? null) : null, $list), true);
            if ($found === false) {
                throw new FlowEditException(["'{$after}' adımı hedef listede yok."]);
            }
            $index = $found + 1;
        }

        return [$listPath, $index];
    }

    /**
     * Görevin yolu (ör. ['tasks', 2, 'then', 0]). Sadece blok olarak tanınan konteynerlerin içine bakılır;
     * gelişmiş blokların iç görevleri tuvalden erişilemez.
     *
     * @param  array<string, mixed>  $flow
     * @return list<string|int>|null
     */
    public function findTask(array $flow, string $id): ?array
    {
        $search = function (mixed $list, array $prefix) use (&$search, $id): ?array {
            if (! is_array($list) || ! array_is_list($list)) {
                return null;
            }

            foreach ($list as $i => $task) {
                if (! is_array($task)) {
                    continue;
                }
                if (($task['id'] ?? null) === $id) {
                    return [...$prefix, $i];
                }
                $kind = $this->blocks->recognize($task)['kind'];
                foreach ($this->blocks->branches($kind) as $branch) {
                    if (($found = $search($task[$branch] ?? null, [...$prefix, $i, $branch])) !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        foreach (FlowGraph::ROOT_LISTS as $root) {
            if (($found = $search($flow[$root] ?? null, [$root])) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Ağaçtaki tüm id'ler (gelişmiş blokların içindekiler ve tetikleyiciler dahil). Kestra'da görev id'leri
     * akış boyunca benzersiz olmalı.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<string>
     */
    public function ids(array $node): array
    {
        $ids = [];
        array_walk_recursive($node, function (mixed $value, string|int $key) use (&$ids): void {
            if ($key === 'id' && is_string($value)) {
                $ids[] = $value;
            }
        });

        return $ids;
    }

    /** @param  array<string, mixed>  $flow */
    private function firstOfKind(array $flow, string $kind): ?string
    {
        foreach (FlowGraph::flatten((new FlowGraph($this->blocks))->build($flow)) as $node) {
            if ($node['kind'] === $kind) {
                return $node['id'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $flow */
    private function triggerIndex(array $flow, string $id): int
    {
        foreach (is_array($flow['triggers'] ?? null) ? $flow['triggers'] : [] as $i => $trigger) {
            if (is_array($trigger) && ($trigger['id'] ?? null) === $id) {
                return $i;
            }
        }

        throw FlowEditException::forTask($id, "'{$id}' adında bir tetikleyici yok.");
    }

    /** @param  array<string, mixed>  $op */
    private static function string(array $op, string $key): string
    {
        if (! is_string($op[$key] ?? null) || $op[$key] === '') {
            throw new FlowEditException(["{$key} zorunlu."]);
        }

        return $op[$key];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private static function settings(array $op): array
    {
        $settings = $op['settings'] ?? [];

        if (! is_array($settings)) {
            throw new FlowEditException(['settings bir nesne olmalı.']);
        }

        return $settings;
    }

    /** @param  list<string|int>  $path */
    private static function get(array $data, array $path): mixed
    {
        foreach ($path as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * @param  list<string|int>  $path
     * @return array<array-key, mixed>
     */
    private static function set(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);

        $data[$key] = $path === [] ? $value : self::set(is_array($data[$key] ?? null) ? $data[$key] : [], $path, $value);

        return $data;
    }

    /**
     * @param  list<string|int>  $path
     * @return array<array-key, mixed>
     */
    private static function remove(array $data, array $path): array
    {
        $index = array_pop($path);
        $list = self::get($data, $path);
        array_splice($list, $index, 1);

        // İsteğe bağlı listeler boşalınca kaldırılır; zorunlu olanlar (tasks, Koşul then) boş kalır ve doğrulayıcı uyarır.
        if ($list === [] && in_array(end($path), ['else', 'errors', 'finally'], true)) {
            $key = array_pop($path);
            $parent = self::get($data, $path);
            unset($parent[$key]);

            return $path === [] ? $parent : self::set($data, $path, $parent);
        }

        return self::set($data, $path, $list);
    }

    /**
     * @param  list<string|int>  $listPath
     * @return array<array-key, mixed>
     */
    private static function insertAt(array $data, array $listPath, int $index, mixed $value): array
    {
        $list = self::get($data, $listPath) ?? [];
        array_splice($list, $index, 0, [$value]);

        return self::set($data, $listPath, $list);
    }
}
