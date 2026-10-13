<?php

namespace App\Services\Claude;

use App\Services\Flows\Catalog;
use App\Services\Settings\AppSettings;

/**
 * resources/prompts/system.md şablonunu katalog ve örnek akışlarla doldurur.
 *
 * Çıktı aynı ayarlarla her istekte aynıdır (önbelleğe alınabilir); ai.data_access ayarına göre iki sürümü vardır. Secret isimleri gibi değişebilen bilgiler
 * buraya değil, get_catalog aracının sonucuna girer.
 */
class SystemPrompt
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly AppSettings $settings,
    ) {}

    public function render(): string
    {
        $template = self::conditionals((string) file_get_contents(resource_path('prompts/system.md')), [
            'data_access' => $this->settings->dataAccessEnabled(),
        ]);

        $examples = array_map(
            fn (string $path) => "```yaml\n".trim((string) file_get_contents($path))."\n```",
            glob(resource_path('prompts/examples/*.yml')) ?: [],
        );

        return strtr($template, [
            '{{KESTRA_VERSION}}' => $this->catalog->kestraVersion(),
            '{{NAMESPACE}}' => $this->catalog->namespace(),
            '{{CATALOG}}' => $this->catalog->summaryForPrompt(),
            '{{EXAMPLES}}' => implode("\n\n", $examples),
        ]);
    }

    /**
     * {{#if ad}}...{{else}}...{{/if}} bloklarını bayrağa göre seçer (iç içe blok yok).
     *
     * @param  array<string, bool>  $flags
     */
    public static function conditionals(string $template, array $flags): string
    {
        return (string) preg_replace_callback(
            '/\{\{#if (\w+)\}\}\n?(.*?)(?:\{\{else\}\}\n?(.*?))?\{\{\/if\}\}\n?/s',
            fn (array $m) => ($flags[$m[1]] ?? false) ? $m[2] : ($m[3] ?? ''),
            $template,
        );
    }
}
