<?php

namespace App\Console\Commands;

use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

#[Signature('kestra:smoke-test
    {--name=Fortis : Akışa gönderilecek girdi}
    {--timeout=90 : Çalışmanın bitmesi için beklenecek en uzun süre (saniye)}
    {--cleanup : Test bittikten sonra akışı Kestra\'dan sil}')]
#[Description('Test akışını Kestra\'ya yükler, çalıştırır ve sonucunu okur (Faz 0 çıkış kriteri)')]
class KestraSmokeTest extends Command
{
    public function handle(KestraClient $kestra): int
    {
        $yaml = file_get_contents(resource_path('kestra/smoke-test.yml'));
        $flow = Yaml::parse($yaml);

        try {
            $this->components->task('Kestra doğrulaması', fn () => $this->validate($kestra, $yaml));
            $revision = null;
            $this->components->task('Akış yükleme', function () use ($kestra, $flow, $yaml, &$revision) {
                $revision = $this->deploy($kestra, $flow['namespace'], $flow['id'], $yaml);
            });
            $this->components->twoColumnDetail("{$flow['namespace']}.{$flow['id']}", "revision {$revision}");

            $execution = $kestra->createExecution($flow['namespace'], $flow['id'], ['name' => $this->option('name')]);
            $this->components->twoColumnDetail('Çalışma', $execution['id']);

            $execution = $this->waitForCompletion($kestra, $execution['id'], (int) $this->option('timeout'));
            $this->report($kestra, $execution);

            if ($this->option('cleanup')) {
                $this->components->task('Akış silme', fn () => $kestra->deleteFlow($flow['namespace'], $flow['id']));
            }
        } catch (KestraException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $execution['state']['current'] === 'SUCCESS' ? self::SUCCESS : self::FAILURE;
    }

    private function validate(KestraClient $kestra, string $yaml): void
    {
        $violations = array_filter(array_column($kestra->validateFlow($yaml), 'constraints'));

        if ($violations !== []) {
            throw new KestraException('Akış geçersiz: '.implode(' | ', $violations));
        }
    }

    /** Akışı yoksa oluşturur, varsa yeni revision olarak günceller ve revision numarasını döner. */
    private function deploy(KestraClient $kestra, string $namespace, string $flowId, string $yaml): ?int
    {
        $saved = $kestra->flowExists($namespace, $flowId)
            ? $kestra->updateFlow($namespace, $flowId, $yaml)
            : $kestra->createFlow($yaml);

        return $saved['revision'] ?? null;
    }

    /** @return array<string, mixed> */
    private function waitForCompletion(KestraClient $kestra, string $executionId, int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;
        $lastState = null;

        do {
            $execution = $kestra->getExecution($executionId);
            $state = $execution['state']['current'];

            if ($state !== $lastState) {
                $this->components->twoColumnDetail('Durum', $state);
                $lastState = $state;
            }

            if (KestraClient::isTerminal($state)) {
                return $execution;
            }

            sleep(1);
        } while (time() < $deadline);

        throw new KestraException("Çalışma {$timeoutSeconds} saniyede bitmedi (son durum: {$lastState}).");
    }

    /** @param  array<string, mixed>  $execution */
    private function report(KestraClient $kestra, array $execution): void
    {
        $this->newLine();
        $this->table(
            ['Adım', 'Durum', 'Süre'],
            array_map(fn (array $run) => [
                $run['taskId'],
                $run['state']['current'],
                $run['state']['duration'] ?? '',
            ], $execution['taskRunList'] ?? []),
        );

        foreach ($kestra->getExecutionLogs($execution['id']) as $log) {
            $this->line(sprintf('  <fg=gray>[%s]</> %s: %s', $log['level'], $log['taskId'] ?? '-', $log['message']));
        }

        $this->newLine();
        $execution['state']['current'] === 'SUCCESS'
            ? $this->components->info('Akış yüklendi, çalıştırıldı ve sonucu okundu.')
            : $this->components->error("Çalışma {$execution['state']['current']} durumunda bitti.");
    }
}
