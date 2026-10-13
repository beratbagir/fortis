<?php

namespace Tests\Feature;

use App\Ai\ChartBlocks;
use App\Models\AiCall;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\FakesDataSources;
use Tests\TestCase;

/**
 * :::chart blokları: şema, sınırlar, HTML reddi, kaynak ve değerlerin bu cevaptaki sorgudan gelmesi.
 */
class ChartBlockTest extends TestCase
{
    use FakesDataSources, RefreshDatabase;

    private const QUERY = 'q_grafik0001';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
        Str::createRandomStringsUsing(fn () => 'grafik0001');
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private static function chart(array $overrides = []): array
    {
        return array_merge([
            'type' => 'bar',
            'title' => 'Satılan koltuk',
            'source' => 'q:'.self::QUERY,
            'x' => ['field' => 'flight_no', 'type' => 'category', 'label' => 'Sefer'],
            'y' => ['unit' => 'adet', 'label' => 'Koltuk', 'min' => 0],
            'series' => [['name' => 'Satılan', 'field' => 'seats_sold']],
            'data' => [['flight_no' => 'FH1256', 'seats_sold' => 170], ['flight_no' => 'FH0812', 'seats_sold' => 150]],
        ], $overrides);
    }

    private static function block(array $chart): string
    {
        return ":::chart\n".json_encode($chart, JSON_UNESCAPED_UNICODE)."\n:::";
    }

    /** Sahte Claude: önce sorgu, sonra verilen metin. */
    private function answer(string $text): Message
    {
        $this->fakeClaude()
            ->toolCalls([['name' => 'query_warehouse', 'input' => ['sql' => 'SELECT flight_no, seats_sold, capacity, remark FROM ires_dim_flight_test LIMIT 10', 'purpose' => 'Satılan koltuk']]])
            ->text($text);
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $this->post("/api/conversations/{$conversation->id}/messages", ['content' => 'Seferlere göre satılan koltuk?'], ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();

        return Message::query()->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    public function test_valid_chart_is_normalized_into_answer_meta(): void
    {
        $message = $this->answer('FH1256 en dolu sefer [^q:'.self::QUERY."].\n\n".self::block(self::chart(['reference' => ['y' => 160, 'label' => 'Hedef'], 'note' => 'Planlı seferler.'])));

        $charts = $message->meta['answer']['charts'];
        $this->assertCount(1, $charts);
        $this->assertSame('bar', $charts[0]['type']);
        $this->assertSame([['flight_no' => 'FH1256', 'seats_sold' => 170], ['flight_no' => 'FH0812', 'seats_sold' => 150]], $charts[0]['data']);
        $this->assertEquals(['y' => 160, 'label' => 'Hedef'], $charts[0]['reference']);
        $this->assertFalse($charts[0]['derived']);
        $this->assertNull(AiCall::query()->whereNotNull('warning')->first());
        // API de grafiği döner (ön yüz metindeki bloğu değil meta'yı çizer).
        $messages = collect($this->getJson("/api/conversations/{$message->conversation_id}")->assertOk()->json('data.messages'));
        $this->assertSame('Satılan koltuk', $messages->firstWhere('id', $message->id)['answer']['charts'][0]['title']);
    }

    public function test_unknown_source_or_value_not_in_query_result_is_not_drawn_and_flagged(): void
    {
        $message = $this->answer(implode("\n\n", [
            self::block(self::chart(['source' => 'q:q_uydurma999'])),
            self::block(self::chart(['data' => [['flight_no' => 'FH1256', 'seats_sold' => 999]]])),
            self::block(self::chart(['data' => [['flight_no' => 'FH9999', 'seats_sold' => 170]]])),
        ]));

        $charts = $message->meta['answer']['charts'];
        $this->assertSame([ChartBlocks::ERROR_SOURCE, ChartBlocks::ERROR_SOURCE, ChartBlocks::ERROR_SOURCE], array_column($charts, 'error'));
        $this->assertArrayNotHasKey('data', $charts[1]);
        $this->assertSame('Değer kaynakta yok (Satılan, satır 1).', $charts[1]['reason']);
        $this->assertSame('chart_source_mismatch: q_uydurma999, '.self::QUERY, AiCall::query()->whereNotNull('warning')->sole()->warning);
    }

    public function test_derived_values_only_check_labels_and_row_count(): void
    {
        $occupancy = self::chart([
            'title' => 'Doluluk',
            'y' => ['unit' => '%'],
            'series' => [['name' => 'Doluluk', 'field' => 'pct']],
            'data' => [['flight_no' => 'FH1256', 'pct' => 89.9], ['flight_no' => 'FH0812', 'pct' => 79.4], ['flight_no' => 'Diğer', 'pct' => 0]],
            'derived' => true,
        ]);
        $notDerived = array_merge($occupancy, ['derived' => false]);
        $tooMany = array_merge($occupancy, ['data' => [...$occupancy['data'], ['flight_no' => 'FH1256', 'pct' => 1]]]);

        $charts = $this->answer(implode("\n\n", array_map(self::block(...), [$occupancy, $notDerived, $tooMany])))->meta['answer']['charts'];

        $this->assertTrue($charts[0]['derived']);
        $this->assertSame(89.9, $charts[0]['data'][0]['pct']);
        $this->assertSame(ChartBlocks::ERROR_SOURCE, $charts[1]['error']);
        $this->assertSame(ChartBlocks::ERROR_SOURCE, $charts[2]['error']);
    }

    public function test_masked_labels_stay_masked_and_raw_personal_values_are_rejected(): void
    {
        $masked = self::chart([
            'x' => ['field' => 'remark', 'type' => 'category'],
            'data' => [['remark' => '[MASKED]', 'seats_sold' => 170]],
        ]);
        $leaked = array_merge($masked, ['data' => [['remark' => 'Grup PNR K7X2PQ, iletişim 0532 123 45 67', 'seats_sold' => 170]]]);

        $message = $this->answer(self::block($masked)."\n\n".self::block($leaked));
        $charts = $message->meta['answer']['charts'];

        $this->assertSame('[MASKED]', $charts[0]['data'][0]['remark']);
        $this->assertSame(ChartBlocks::ERROR_SOURCE, $charts[1]['error']);
        $this->assertStringNotContainsString('K7X2PQ', json_encode($message->meta['answer']));
    }

    public function test_schema_html_and_limits_are_enforced(): void
    {
        $known = [self::QUERY];
        $rows = fn () => ['columns' => ['flight_no', 'seats_sold'], 'rows' => [['FH1256', 170], ['FH0812', 150]]];
        $first = fn (array $chart) => ChartBlocks::validate(self::block($chart), $known, $rows)['charts'][0];

        $this->assertArrayNotHasKey('error', $first(self::chart()));
        $series = array_map(fn ($i) => ['name' => "S{$i}", 'field' => "f{$i}"], range(1, 7));
        foreach ([
            'html' => self::chart(['title' => '<script>alert(1)</script>']),
            'svg' => self::chart(['note' => '<svg onload=x>']),
            'handler' => self::chart(['series' => [['name' => 'x onerror=alert(1)', 'field' => 'seats_sold']]]),
            'type' => self::chart(['type' => 'donut']),
            'series' => self::chart(['series' => $series]),
            'points' => self::chart(['data' => array_fill(0, 401, ['flight_no' => 'FH1256', 'seats_sold' => 170])]),
            'string number' => self::chart(['data' => [['flight_no' => 'FH1256', 'seats_sold' => '170']]]),
            'unknown key' => self::chart(['html' => 'x']),
            'bad date' => self::chart(['x' => ['field' => 'flight_no', 'type' => 'date']]),
            'unit' => self::chart(['y' => ['unit' => 'kg']]),
        ] as $case => $chart) {
            $this->assertSame(ChartBlocks::ERROR_INVALID, $first($chart)['error'] ?? null, $case);
        }
        $this->assertSame(ChartBlocks::ERROR_INVALID, ChartBlocks::validate(":::chart\nnot json\n:::", $known, $rows)['charts'][0]['error']);

        // En fazla 3 grafik: dördüncüsü çizilmez.
        $four = ChartBlocks::validate(implode("\n\n", array_fill(0, 4, self::block(self::chart()))), $known, $rows)['charts'];
        $this->assertSame([null, null, null, ChartBlocks::ERROR_INVALID], array_map(fn ($c) => $c['error'] ?? null, $four));
    }

    public function test_dates_match_source_timestamps_and_rounding_is_allowed(): void
    {
        $rows = fn () => ['columns' => ['day', 'otp'], 'rows' => [['2026-10-05 00:00:00', 82.4123], ['2026-10-06 00:00:00', 79.0]]];
        $chart = self::chart([
            'type' => 'line',
            'x' => ['field' => 'day', 'type' => 'date'],
            'y' => ['unit' => '%'],
            'series' => [['name' => 'OTP', 'field' => 'otp']],
            'data' => [['day' => '2026-10-05', 'otp' => 82.4], ['day' => '2026-10-06', 'otp' => 79]],
        ]);

        $this->assertArrayNotHasKey('error', ChartBlocks::validate(self::block($chart), [self::QUERY], $rows)['charts'][0]);
    }
}
