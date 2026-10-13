<?php

namespace App\Console\Commands;

use App\Ai\PromptClock;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\ClaudeChat;
use App\Services\Claude\ClaudeException;
use App\Services\Settings\AppSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Signature('fortis:eval {--only= : Sadece bu soru id\'leri (virgülle)} {--path= : Soru dosyası (varsayılan tests/eval/questions.json)} {--max-cost= : Bütçe ($): sıradaki soru bu sınırı aşabilecekse yeni soru başlatılmaz}')]
#[Description('Değerlendirme setini gerçek modele sorar: doğru araç, gereksiz akış, tablo ve kaynak. Gerçek API çağrısı yapar (ücretli).')]
class RunEval extends Command
{
    public function handle(AppSettings $settings): int
    {
        if (! $settings->dataAccessEnabled()) {
            $this->error('AI veri erişimi kapalı (ai.data_access = off). Değerlendirme veri sorgularını ölçer; önce ayarı açın.');

            return self::FAILURE;
        }

        $file = $this->option('path') ?: base_path('tests/eval/questions.json');
        $questions = json_decode((string) file_get_contents($file), true)['questions'] ?? [];
        $only = array_filter(explode(',', (string) $this->option('only')));
        $questions = array_values(array_filter($questions, fn (array $q) => $only === [] || in_array($q['id'], $only, true)));

        $results = [];
        $skipped = [];
        $maxCost = $this->option('max-cost') !== null ? (float) $this->option('max-cost') : null;
        foreach ($questions as $question) {
            // Bütçe: harcanan + şimdiye kadarki en pahalı soru (en az $0,60) sınırı aşacaksa kalan sorular atlanır.
            $spent = array_sum(array_column($results, 'cost_usd'));
            if ($maxCost !== null && $spent + max(0.6, ...array_column($results, 'cost_usd') ?: [0]) > $maxCost) {
                $skipped[] = $question['id'];

                continue;
            }
            $this->line("→ {$question['id']}: {$question['question']}".(isset($question['now']) ? " (şimdi: {$question['now']})" : ''));
            // Gece yarısı gibi saat testleri: soru sabit bir "şimdi" ile sorulur.
            if (isset($question['now'])) {
                Carbon::setTestNow(Carbon::parse($question['now']));
            }
            try {
                $results[] = $result = $this->askQuestion($question);
            } finally {
                Carbon::setTestNow();
            }
            $this->line(sprintf('   %s  araçlar: %s  maliyet: $%.3f', $result['pass'] ? 'GEÇTİ' : 'KALDI', implode(', ', $result['tools']) ?: '-', $result['cost_usd']));
            foreach ($result['failures'] as $failure) {
                $this->line("   · {$failure}");
            }
        }

        $passed = count(array_filter($results, fn (array $r) => $r['pass']));
        // Doğru araç oranı: beklenen aracı olan (expect_tools veya expect_flow) her soru dahil; hiç araç çağırmayan soru başarısız sayılır.
        $graded = array_filter($results, fn (array $r) => array_key_exists('tool', $r['checks']));
        $toolHits = count(array_filter($graded, fn (array $r) => $r['checks']['tool']));
        $summary = [
            'run_at' => now()->toIso8601String(),
            'questions' => count($results),
            'passed' => $passed,
            'correct_tool_ratio' => count($graded) ? round($toolHits / count($graded), 3) : null,
            'total_cost_usd' => round(array_sum(array_column($results, 'cost_usd')), 4),
            'skipped_for_budget' => $skipped,
        ];

        $path = 'eval/'.now()->format('Y-m-d_His').'.json';
        Storage::disk('local')->put($path, json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info("{$passed}/{$summary['questions']} geçti · doğru araç oranı ".($summary['correct_tool_ratio'] === null ? '— (araç beklenen soru yok)' : '%'.round($summary['correct_tool_ratio'] * 100))." · maliyet \${$summary['total_cost_usd']}");
        if ($skipped !== []) {
            $this->warn('Bütçe sınırı nedeniyle atlanan sorular ('.count($skipped).'): '.implode(', ', $skipped));
        }
        $this->line('Rapor: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private function askQuestion(array $question): array
    {
        $conversation = Conversation::create(['title' => "[eval] {$question['id']}"]);
        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => $question['question']]], 'text' => $question['question']]);

        $error = null;
        try {
            app(ClaudeChat::class)->reply($conversation, fn () => null);
        } catch (ClaudeException $e) {
            $error = $e->getMessage();
        }

        $messages = $conversation->messages()->orderBy('id')->get();
        $tools = $messages->flatMap(fn (Message $m) => collect($m->content)->where('type', 'tool_use')->pluck('name'))->values()->all();
        $last = $messages->where('role', Message::ROLE_ASSISTANT)->last();
        $answer = (string) $last?->text;
        // Doğrulanmış grafikler (kaynağı ve değerleri sorgudan); hatalı bloklar sayılmaz.
        $charts = array_values(array_filter($last?->meta['answer']['charts'] ?? [], fn (array $c) => ! isset($c['error'])));

        $checks = array_filter([
            'tool' => match (true) {
                isset($question['expect_tools']) => array_intersect($question['expect_tools'], $tools) !== [],
                ! empty($question['expect_flow']) => array_intersect(['validate_flow', 'propose_flow'], $tools) !== [],
                default => null,
            },
            'no_flow' => ! empty($question['expect_no_flow']) ? ! in_array('propose_flow', $tools, true) : null,
            'flow' => ! empty($question['expect_flow']) ? in_array('propose_flow', $tools, true) || in_array('validate_flow', $tools, true) : null,
            'table' => ! empty($question['expect_table']) ? self::hasTable($answer) || $charts !== [] : null,
            'chart' => isset($question['expect_chart']) ? collect($charts)->contains(fn (array $c) => in_array($c['type'], $question['expect_chart'], true)) : null,
            'no_chart' => ! empty($question['expect_no_chart']) ? ! str_contains($answer, ':::chart') : null,
            'single_measure' => ! empty($question['expect_single_measure']) ? $charts !== [] && ! collect($charts)->contains(fn (array $c) => self::mixesMeasures($c)) : null,
            'no_table' => ! empty($question['expect_no_table']) ? ! self::hasTable($answer) : null,
            'citation' => ! empty($question['expect_citation']) ? (bool) preg_match('/\[\^q:[A-Za-z0-9_]+\]/', $answer) : null,
            'source' => ! empty($question['expect_source']) ? (bool) preg_match('/Kaynak\s*:/iu', $answer) : null,
            'date' => isset($question['expect_date']) ? self::mentionsDate($answer, $question['expect_date']) : null,
            'utc_range' => ! empty($question['expect_utc_from']) && isset($question['expect_date']) ? self::queriedFrom($messages, $question['expect_date']) : null,
            'no_error' => $error === null,
        ], fn ($check) => $check !== null);

        $labels = ['tool' => 'Beklenen araç kullanılmadı', 'no_flow' => 'Gereksiz akış önerildi', 'flow' => 'Akış önerilmedi', 'table' => 'Tablo yok', 'no_table' => 'Gereksiz tablo', 'chart' => 'Beklenen grafik yok (veya doğrulanamadı)', 'no_chart' => 'Gereksiz grafik', 'single_measure' => 'Grafik yok veya bir grafikte iki farklı ölçü', 'citation' => 'Kaynak numarası yok', 'date' => 'Cevapta doğru tarih (Türkiye) yok', 'utc_range' => 'Sorguda doğru tarih aralığı yok (APM: UTC başlangıcı, IBS: Türkiye tarihi)', 'source' => 'Kaynak satırı yok', 'no_error' => "Hata: {$error}"];

        return [
            'id' => $question['id'],
            'question' => $question['question'],
            'pass' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'failures' => array_values(array_map(fn (string $key) => $labels[$key], array_keys(array_filter($checks, fn (bool $ok) => ! $ok)))),
            'tools' => array_values(array_unique($tools)),
            'answer' => $answer,
            'cost_usd' => (float) $conversation->aiCalls()->sum('cost_usd'),
            'conversation_id' => $conversation->id,
        ];
    }

    /** Bir grafikte yüzde (OTP) ile sayı serisi birlikte mi (çift eksen yerine iki grafik olmalı). */
    private static function mixesMeasures(array $chart): bool
    {
        $percent = array_map(fn (array $s) => (bool) preg_match('/otp|%|oran|zamanında/iu', $s['name'].' '.$s['field']), $chart['series']);

        return in_array(true, $percent, true) && in_array(false, $percent, true);
    }

    private static function hasTable(string $answer): bool
    {
        return (bool) preg_match('/^\s*\|.*\|\s*$\n^\s*\|[\s:|-]+\|\s*$/m', $answer);
    }

    /** Cevap beklenen göreli tarihi (Türkiye saatine göre) gün ve ay adıyla anıyor mu; aralıkta son gün. */
    private static function mentionsDate(string $answer, string $relative): bool
    {
        [$from, $to] = PromptClock::ranges()[$relative];
        $day = $to->subDay();
        $month = mb_substr(PromptClock::date($day), mb_strlen((string) $day->day) + 1, 3);

        return (bool) preg_match('/\b'.$day->day.'(\s*'.preg_quote($month, '/').'|\.'.str_pad((string) $day->month, 2, '0', STR_PAD_LEFT).')/iu', $answer);
    }

    /**
     * Bir veri sorgusu beklenen aralığı doğru başlatıyor mu: APM'de dateFrom = aralığın UTC başlangıcı, IBS'te
     * (tarih sütunu) Türkiye'deki ilk gün.
     */
    private static function queriedFrom(Collection $messages, string $relative): bool
    {
        $from = PromptClock::ranges()[$relative][0];
        $apm = '/dateFrom='.preg_quote($from->utc()->format('Y-m-d\TH:i'), '/').'(:00)?(\.0+)?Z/';
        $ibsDay = $from->format('Y-m-d');

        return $messages->flatMap(fn (Message $m) => collect($m->content)->where('type', 'tool_use'))->contains(fn (array $b) => match ($b['name']) {
            'query_apm' => preg_match($apm, urldecode((string) ($b['input']['path'] ?? ''))) === 1,
            'query_warehouse' => str_contains((string) json_encode($b['input']), $ibsDay),
            default => false,
        });
    }
}
