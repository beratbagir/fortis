<?php

namespace App\Services\Claude;

/**
 * Bir araç çağrısının sonucu: Claude'a dönen metin ve (varsa) kullanıcıya gösterilecek akış kartı.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $card
     * @param  array<string, mixed>|null  $meta  Sadece arayüz için (ör. veri sorgusunun amacı ve satır sayısı); Claude'a gitmez.
     */
    private function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
        public readonly ?array $card = null,
        public readonly ?array $meta = null,
    ) {}

    /**
     * Aynı sonucun içeriği değiştirilmiş hali (AiDataGate'ten geçirilmiş veri).
     *
     * @param  array<array-key, mixed>  $data
     */
    public function withData(array $data): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->isError, $this->card, $this->meta);
    }

    /** @param  array<string, mixed>  $meta */
    public function withMeta(array $meta): self
    {
        return new self($this->content, $this->isError, $this->card, $meta);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $card
     */
    public static function json(array $data, bool $isError = false, ?array $card = null): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $isError, $card);
    }

    public static function error(string $message): self
    {
        return self::json(['error' => $message], isError: true);
    }

    /**
     * Girdi şemaya uymuyor (araç girdileri canlı aktığı için kesik veya bozuk gelebilir). Araç çalıştırılmaz.
     *
     * @param  array<string, mixed>  $input
     */
    public static function invalidInput(array $input, string $reason): self
    {
        return self::json(['INVALID_INPUT' => $reason, 'received' => $input], isError: true);
    }
}
