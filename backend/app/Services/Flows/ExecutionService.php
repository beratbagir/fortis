<?php

namespace App\Services\Flows;

use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Support\Carbon;

/**
 * Akışları kullanıcının isteğiyle çalıştırır, durumlarını Kestra'dan okur ve bekleyen çalışmaları devam ettirir.
 */
class ExecutionService
{
    /** Gösterilen en fazla log satırı. */
    private const MAX_LOGS = 200;

    public function __construct(private readonly KestraClient $kestra) {}

    /**
     * @param  array<string, mixed>  $inputs
     *
     * @throws ExecutionException
     */
    public function start(Flow $flow, array $inputs, ?Conversation $conversation, string $startedBy): Execution
    {
        $version = $flow->currentVersion;

        if (! $flow->isInKestra() || $version === null) {
            throw new ExecutionException('Akış henüz Kestra\'ya yüklenmedi.');
        }

        $inputs = $this->checkInputs(FlowDefinition::fromYaml($version->yaml)->inputs(), $inputs);

        try {
            $created = $this->kestra->createExecution($flow->namespace, $flow->flow_id, $inputs, array_filter([
                'source' => 'fortis-chat',
                'conversation' => $conversation?->id,
            ]));
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        $execution = Execution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'conversation_id' => $conversation?->id,
            'kestra_execution_id' => $created['id'],
            'state' => $created['state']['current'] ?? 'CREATED',
            'inputs' => $inputs,
            'started_by' => $startedBy,
            'triggered_by_user_id' => auth()->id(),
        ]);

        return $this->refresh($execution);
    }

    /**
     * Kestra'dan güncel durumu, adımları ve logları okur.
     *
     * @throws ExecutionException
     */
    public function refresh(Execution $execution): Execution
    {
        try {
            $remote = $this->kestra->getExecution($execution->kestra_execution_id);
            $logs = $this->kestra->getExecutionLogs($execution->kestra_execution_id);
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        $state = $remote['state']['current'];
        // Görev çıktıları (uçuş, personel verisi olabilir) saklanmaz; sadece özet (kayıt sayısı, HTTP kodu).
        $taskRuns = array_map(fn (array $run) => [
            'task_id' => $run['taskId'],
            'state' => $run['state']['current'],
            'started_at' => $run['state']['startDate'] ?? ($run['state']['histories'][0]['date'] ?? null),
            'duration' => $run['state']['duration'] ?? null,
            'attempts' => count($run['attempts'] ?? []),
            'value' => $run['value'] ?? null,
            'output_summary' => self::outputSummary($run['outputs'] ?? null),
        ], $remote['taskRunList'] ?? []);

        $paused = null;
        if ($state === 'PAUSED' && $execution->flowVersion !== null) {
            foreach ($taskRuns as $run) {
                if ($run['state'] === 'PAUSED') {
                    $paused = FlowDefinition::fromYaml($execution->flowVersion->yaml)->pause($run['task_id']);
                    break;
                }
            }
        }

        $execution->update([
            'state' => $state,
            'started_at' => isset($remote['state']['startDate']) ? Carbon::parse($remote['state']['startDate']) : $execution->started_at,
            'ended_at' => KestraClient::isTerminal($state) && isset($remote['state']['endDate']) ? Carbon::parse($remote['state']['endDate']) : null,
            'snapshot' => [
                'task_runs' => $taskRuns,
                'logs' => array_map(fn (array $log) => [
                    'timestamp' => $log['timestamp'] ?? null,
                    'level' => $log['level'],
                    'task_id' => $log['taskId'] ?? null,
                    'message' => mb_substr((string) $log['message'], 0, 2000),
                ], array_slice($logs, -self::MAX_LOGS)),
                'paused' => $paused,
                'duration' => $remote['state']['duration'] ?? null,
            ],
        ]);

        return $execution;
    }

    /**
     * Onay bekleyen çalışmayı devam ettirir. Reddetmede, bekleyen görevin tek bir BOOL onay girdisi varsa
     * bu girdi `false` gönderilir (akışın "reddedildi" dalı çalışır); yoksa çalışma durdurulur.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws ExecutionException
     */
    public function resume(Execution $execution, bool $approved, array $inputs = []): Execution
    {
        $execution = $this->refresh($execution);
        $paused = $execution->snapshot['paused'] ?? null;

        if ($execution->state !== 'PAUSED' || $paused === null) {
            throw new ExecutionException('Çalışma şu an onay beklemiyor.');
        }

        $approvalInput = $paused['approval_input'];

        if (! $approved && $approvalInput === null) {
            return $this->kill($execution);
        }

        if ($approvalInput !== null) {
            $inputs[$approvalInput] = $approved;
        }

        $values = $this->checkInputs($paused['inputs'], $inputs);

        try {
            $this->kestra->resumeExecution($execution->kestra_execution_id, $values);
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        return $this->refresh($execution);
    }

    /**
     * Editördeki "Test çalıştır": kuru çalıştırma kopyasını test namespace'ine yükler ve bir kez çalıştırır.
     * Kopya (adımlar Log'a çevrilmiş olsa da) yükleme modunda yeniden doğrulanır.
     *
     * @param  array<string, mixed>  $inputs
     *
     * @throws ExecutionException
     */
    public function startTest(Flow $flow, FlowVersion $version, string $dryYaml, string $namespace, array $inputs, string $startedBy): Execution
    {
        $inputs = $this->checkInputs(FlowDefinition::fromYaml($version->yaml)->inputs(), $inputs);

        try {
            $this->kestra->flowExists($namespace, $flow->flow_id)
                ? $this->kestra->updateFlow($namespace, $flow->flow_id, $dryYaml)
                : $this->kestra->createFlow($dryYaml);

            $created = $this->kestra->createExecution($namespace, $flow->flow_id, $inputs, ['source' => 'fortis-test']);
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        $execution = Execution::create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'kestra_execution_id' => $created['id'],
            'state' => $created['state']['current'] ?? 'CREATED',
            'inputs' => $inputs,
            'started_by' => $startedBy,
            'triggered_by_user_id' => auth()->id(),
            'is_test' => true,
        ]);

        return $this->refresh($execution);
    }

    /**
     * Kestra'da zamanlayıcı veya webhook ile başlamış bir çalışmanın Fortis kaydını oluşturur (Çalışmalar ekranı).
     * Sadece Fortis'in bildiği akışların (ve test kopyalarının) çalışmaları alınır.
     *
     * @throws ExecutionException
     */
    public function import(string $kestraExecutionId): ?Execution
    {
        try {
            $remote = $this->kestra->getExecution($kestraExecutionId);
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        $namespace = $remote['namespace'] ?? null;
        $isTest = $namespace === config('kestra.test_namespace');

        if ($namespace !== config('kestra.namespace') && ! $isTest) {
            return null;
        }

        $flow = Flow::query()->where('namespace', config('kestra.namespace'))->where('flow_id', $remote['flowId'] ?? null)->first();

        if ($flow === null) {
            return null;
        }

        // Yayındaki çalışma: Kestra revision'ı eşleşen sürüm; test çalışması: o anki son sürüm.
        $version = $isTest
            ? $flow->latestVersion()
            : ($flow->versions()->where('kestra_revision', $remote['flowRevision'] ?? null)->first() ?? $flow->currentVersion);

        $execution = Execution::firstOrCreate(['kestra_execution_id' => $kestraExecutionId], [
            'flow_id' => $flow->id,
            'flow_version_id' => $version?->id,
            'state' => $remote['state']['current'] ?? 'CREATED',
            'inputs' => [],
            'started_by' => isset($remote['trigger']['type']) ? class_basename(str_replace('.', '\\', $remote['trigger']['type'])) : 'Kestra',
            'is_test' => $isTest,
        ]);
        // İçe aktarma bir okuma isteğinin parçası; yanıt 201 değil 200 olmalı.
        $execution->wasRecentlyCreated = false;

        return $this->refresh($execution);
    }

    /** Görev çıktısının özeti: kayıt sayısı, HTTP kodu. Değerlerin kendisi tutulmaz. */
    private static function outputSummary(mixed $outputs): ?string
    {
        if (! is_array($outputs)) {
            return null;
        }

        return match (true) {
            isset($outputs['size']) && is_numeric($outputs['size']) => "{$outputs['size']} kayıt",
            isset($outputs['rows']) && is_array($outputs['rows']) => count($outputs['rows']).' kayıt',
            isset($outputs['row']) => '1 kayıt',
            isset($outputs['code']) && is_numeric($outputs['code']) => "HTTP {$outputs['code']}",
            isset($outputs['values']) && is_array($outputs['values']) => count($outputs['values']).' değer',
            default => null,
        };
    }

    /** @throws ExecutionException */
    public function kill(Execution $execution): Execution
    {
        try {
            $this->kestra->killExecution($execution->kestra_execution_id);
        } catch (KestraException $e) {
            throw new ExecutionException($e->getMessage());
        }

        return $this->refresh($execution);
    }

    /**
     * Kullanıcı girdilerini akışın tanımladığı girdilere göre süzer: tanımsız girdiler reddedilir,
     * zorunlu girdiler istenir, değerler metne çevrilir (Kestra tipleri kendisi doğrular).
     *
     * @param  list<array{id: string, type: string, required: bool}>  $definitions
     * @param  array<string, mixed>  $inputs
     * @return array<string, string>
     *
     * @throws ExecutionException
     */
    private function checkInputs(array $definitions, array $inputs): array
    {
        $known = array_column($definitions, null, 'id');

        foreach (array_keys($inputs) as $id) {
            if (! isset($known[$id])) {
                throw new ExecutionException("Akışta '{$id}' adında bir girdi yok.");
            }
        }

        $values = [];
        foreach ($known as $id => $definition) {
            $value = $inputs[$id] ?? null;

            if ($value === null || $value === '') {
                if ($definition['required']) {
                    throw new ExecutionException("'{$id}' girdisi zorunlu.");
                }

                continue;
            }

            if (! is_scalar($value)) {
                throw new ExecutionException("'{$id}' girdisi bir metin, sayı veya evet/hayır olmalı.");
            }

            $values[$id] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $values;
    }
}
