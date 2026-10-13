<?php

namespace App\Console\Commands;

use App\Services\Flows\Catalog;
use App\Services\Flows\FlowValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kestra:check-catalog')]
#[Description('Katalogdaki örnekleri ve prompt örnek akışlarını Kestra\'ya karşı doğrular (Kestra sürümü değişince çalıştırın)')]
class KestraCheckCatalog extends Command
{
    public function handle(Catalog $catalog, FlowValidator $validator): int
    {
        $failures = 0;

        foreach ($catalog->forModel()['entries'] as $entry) {
            $failures += $this->report($entry['type'], $validator->validate($this->wrap($entry, $catalog->namespace())));
        }

        foreach (glob(resource_path('prompts/examples/*.yml')) as $path) {
            $failures += $this->report(basename($path), $validator->validate((string) file_get_contents($path)));
        }

        if ($failures > 0) {
            $this->components->error("{$failures} örnek doğrulamadan geçmedi.");

            return self::FAILURE;
        }

        $this->components->info('Tüm örnekler geçerli.');

        return self::SUCCESS;
    }

    /**
     * Katalog örneğini doğrulanabilir en küçük akışa yerleştirir.
     *
     * @param  array<string, mixed>  $entry
     */
    private function wrap(array $entry, string $namespace): string
    {
        $example = preg_replace('/^/m', '  ', $entry['example']);
        $header = "id: catalog-check\nnamespace: {$namespace}\n";

        // Örnekler başka görevlerin çıktılarına ({{ outputs.check_site... }}) atıf yapabilir; ifadeler doğrulamada çalıştırılmaz.
        return $entry['kind'] === 'trigger'
            ? $header."tasks:\n  - id: noop\n    type: io.kestra.plugin.core.log.Log\n    message: ok\ntriggers:\n{$example}\n"
            : $header."tasks:\n{$example}\n";
    }

    /**
     * @param  array{valid: bool, errors: list<string>, warnings: list<string>, missing_secrets: list<string>}  $result
     */
    private function report(string $label, array $result): int
    {
        if (! $result['valid']) {
            $this->components->twoColumnDetail($label, '<fg=red>GEÇERSİZ</>');
            foreach ($result['errors'] as $error) {
                $this->line("    <fg=red>•</> {$error}");
            }

            return 1;
        }

        $this->components->twoColumnDetail($label, '<fg=green>geçerli</>');
        foreach ($result['warnings'] as $warning) {
            $this->line("    <fg=yellow>•</> {$warning}");
        }

        return 0;
    }
}
