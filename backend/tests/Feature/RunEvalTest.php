<?php

namespace Tests\Feature;

use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

class RunEvalTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    public function test_eval_refuses_when_data_access_is_off(): void
    {
        $this->artisan('fortis:eval')->assertFailed();
    }

    public function test_eval_scores_tool_choice_flow_table_and_source(): void
    {
        Storage::fake('local');
        $this->fakeDataSources(AppSettings::DATA_ACCESS_MASKED);
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z', 'purpose' => 'Dünkü uçuşlar']]])
            ->text("Dün en uzun rötar **FH1256** seferindeydi.\n\n| Sefer | Rötar (dk) |\n|---|---:|\n| FH1256 | 142 |\n\n*Kaynak: APM (11 Eki 2026).*")
            ->text('Dünkü rötarlar için bir akış hazırlayabilirim.');

        $this->artisan('fortis:eval', ['--only' => 'apm-top-delays,apm-ayt-delays-week'])
            ->expectsOutputToContain('1/2 geçti')
            ->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('eval')[0]), true);
        $this->assertTrue($report['results'][0]['pass']);
        $this->assertSame(['query_apm'], $report['results'][0]['tools']);
        $this->assertFalse($report['results'][1]['pass']);
        $this->assertContains('Beklenen araç kullanılmadı', $report['results'][1]['failures']);
        $this->assertSame(0.5, $report['summary']['correct_tool_ratio']);
    }

    public function test_flow_question_without_any_tool_call_counts_against_the_tool_ratio(): void
    {
        Storage::fake('local');
        $this->fakeDataSources(AppSettings::DATA_ACCESS_MASKED);
        $this->fakeClaude()->text('E-posta adresin nedir?');

        $this->artisan('fortis:eval', ['--only' => 'automation-morning'])->expectsOutputToContain('0/1 geçti · doğru araç oranı %0')->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('eval')[0]), true);
        $this->assertSame(['Beklenen araç kullanılmadı', 'Akış önerilmedi'], $report['results'][0]['failures']);
        $this->assertSame(0.0, (float) $report['summary']['correct_tool_ratio']);
    }

    public function test_date_questions_check_turkey_date_and_utc_range_with_a_fixed_midnight_clock(): void
    {
        Storage::fake('local');
        $this->fakeDataSources(AppSettings::DATA_ACCESS_MASKED);
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T21:00:00Z&dateTo=2026-10-12T21:00:00Z', 'purpose' => 'Dünkü uçuşlar']]])
            ->text('Dün (12 Ekim 2026) **2 sefer** vardı [^q:q_x].');

        $this->artisan('fortis:eval', ['--only' => 'date-midnight-yesterday'])->expectsOutputToContain('1/1 geçti')->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('eval')[0]), true);
        $this->assertTrue($report['results'][0]['checks']['date']);
        $this->assertTrue($report['results'][0]['checks']['utc_range']);
        // Saat sabitlemesi soru bitince kalkar.
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function test_wrong_utc_day_fails_the_date_checks(): void
    {
        Storage::fake('local');
        $this->fakeDataSources(AppSettings::DATA_ACCESS_MASKED);
        // UTC'ye göre "dün" (11 Ekim) sorgulanmış ve yazılmış: iki kontrol de kalır.
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_apm', 'input' => ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z', 'purpose' => 'Dünkü uçuşlar']]])
            ->text('Dün (11 Ekim 2026) **2 sefer** vardı [^q:q_x].');

        $this->artisan('fortis:eval', ['--only' => 'date-midnight-yesterday'])->expectsOutputToContain('0/1 geçti')->assertSuccessful();

        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('eval')[0]), true);
        $this->assertContains('Cevapta doğru tarih (Türkiye) yok', $report['results'][0]['failures']);
        $this->assertContains('Sorguda doğru tarih aralığı yok (APM: UTC başlangıcı, IBS: Türkiye tarihi)', $report['results'][0]['failures']);
    }
}
