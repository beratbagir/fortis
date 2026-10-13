<?php

namespace App\Console\Commands;

use App\Services\Kestra\KestraClient;
use App\Services\Kestra\KestraException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kestra:ping')]
#[Description('Kestra bağlantısını, sürümünü ve kimlik bilgilerini kontrol eder')]
class KestraPing extends Command
{
    public function handle(KestraClient $kestra): int
    {
        try {
            $info = $kestra->serverInfo();
            $this->components->twoColumnDetail('Adres', config('kestra.url'));
            $this->components->twoColumnDetail('Sürüm', ($info['version'] ?? '?').' '.($info['edition'] ?? ''));
            $this->components->twoColumnDetail('Basic auth', ($info['isBasicAuthInitialized'] ?? false) ? 'açık' : '<fg=red>KAPALI</>');

            $this->warnOnVersionMismatch($info['version'] ?? null);

            $kestra->assertAuthenticated();
            $this->components->info('Kimlik doğrulama başarılı.');

            return self::SUCCESS;
        } catch (KestraException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function warnOnVersionMismatch(?string $serverVersion): void
    {
        $configured = ltrim((string) config('kestra.version'), 'v');

        if ($serverVersion !== null && $serverVersion !== $configured) {
            $this->components->warn("KESTRA_VERSION={$configured} ama sunucu {$serverVersion}. Uç noktalar sürüme göre seçildiği için .env'i düzeltin.");
        }
    }
}
