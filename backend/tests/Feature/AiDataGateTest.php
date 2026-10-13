<?php

namespace Tests\Feature;

use App\Ai\AiDataGate;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiDataGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): AiDataGate
    {
        return $this->app->make(AiDataGate::class);
    }

    /** @return array<string, array{string}> */
    public static function personalColumns(): array
    {
        return [
            'pax_name' => ['pax_name'], 'email' => ['email'], 'phone' => ['phone'], 'pnr' => ['pnr'],
            'camelCase' => ['contactEmail'], 'öneki' => ['pax_first_name'], 'ticket' => ['ticket_number'],
            'passport' => ['passport_no'], 'doğum' => ['birth_date'], 'tc' => ['national_id'], 'serbest metin' => ['remarks'],
        ];
    }

    #[DataProvider('personalColumns')]
    public function test_personal_columns_are_masked_by_name(string $column): void
    {
        $result = $this->gate()->table('IBS', [$column, 'dep'], [[$column => 'Değer', 'dep' => 'AYT']]);

        $this->assertSame([[AiDataGate::MASK, 'AYT']], $result['rows']);
        $this->assertSame([$column], $result['masked_fields']);
        $this->assertSame(1, $result['masked_values']);
    }

    public function test_count_columns_and_operational_columns_are_kept(): void
    {
        $result = $this->gate()->table('IBS', ['pnr_count', 'total_pax', 'flight_no', 'airport_name', 'seats_sold'], [
            ['pnr_count' => 12, 'total_pax' => 180, 'flight_no' => 'FH1256', 'airport_name' => 'Antalya', 'seats_sold' => 170],
        ]);

        $this->assertSame([[12, 180, 'FH1256', 'Antalya', 170]], $result['rows']);
        $this->assertSame([], $result['masked_fields']);
    }

    /** @return array<string, array{string, string}> */
    public static function freeText(): array
    {
        return [
            'TC kimlik' => ['Yolcu TC 12345678950 ile geldi', 'Yolcu TC [MASKED] ile geldi'],
            'e-posta' => ['İletişim: veli.yolcu@ornek.com', 'İletişim: [MASKED]'],
            'cep telefonu' => ['Aranacak: 0532 123 45 67', 'Aranacak: [MASKED]'],
            'uluslararası telefon' => ['Tel +49 211 1234 5678', 'Tel [MASKED]'],
            'PNR' => ['Rezervasyon K7X2PQ iptal', 'Rezervasyon [MASKED] iptal'],
            'bilet no' => ['Bilet 235-1234567890 iade', 'Bilet [MASKED] iade'],
            'pasaport' => ['Pasaport U12345678 süresi dolmuş', 'Pasaport [MASKED] süresi dolmuş'],
        ];
    }

    #[DataProvider('freeText')]
    public function test_personal_data_inside_text_is_masked(string $text, string $expected): void
    {
        $this->assertSame([$expected, 1], AiDataGate::maskText($text));
    }

    public function test_operational_codes_are_not_mistaken_for_personal_data(): void
    {
        foreach (['FH1256 AYT-DUS 142 dk', 'Uçak TC-FHB, kod 41', 'Çağrı FHY123', '2026-10-11T03:40:00Z', '/Date(1789776000000)/', 'AYTHAJ'] as $text) {
            $this->assertSame([$text, 0], AiDataGate::maskText($text), $text);
        }
    }

    public function test_operational_code_columns_keep_code_like_values_that_look_like_a_pnr(): void
    {
        // Canlı denemede bulunan yanlış pozitif: sonu harfli sefer kodu (FH489P) PNR kuralına takılıyordu.
        $this->assertSame([AiDataGate::MASK, 1], AiDataGate::maskText('FH489P'));

        $columns = ['flight_no', 'leg_id', 'call_sign', 'aircraft_reg', 'remarks', 'note'];
        $rows = [['flight_no' => 'FH489P', 'leg_id' => 'LG34X61', 'call_sign' => 'FHY48P', 'aircraft_reg' => 'TCFHB2', 'remarks' => 'FH489P', 'note' => 'K7X2PQ']];
        $result = $this->gate()->table('APM', $columns, $rows);

        $this->assertSame(['FH489P', 'LG34X61', 'FHY48P', 'TCFHB2', AiDataGate::MASK, AiDataGate::MASK], $result['rows'][0]);

        // APM'nin iç içe ham yanıtında da (flightNumber, legId, actualAircraftRegistration).
        $nested = $this->gate()->structure('APM', ['legs' => [['flightNumberInfo' => ['flightNumber' => '489P', 'designator' => 'FH489P'], 'legId' => 'AB12CD', 'aircraftInfo' => ['actualAircraftRegistration' => 'TCFHB2']]]]);
        $this->assertSame('FH489P', $nested['data']['legs'][0]['flightNumberInfo']['designator']);
        $this->assertSame('AB12CD', $nested['data']['legs'][0]['legId']);
        $this->assertSame('TCFHB2', $nested['data']['legs'][0]['aircraftInfo']['actualAircraftRegistration']);
    }

    public function test_operational_columns_still_mask_contact_data_and_national_ids(): void
    {
        $rows = [['flight_no' => 'FH489P ops@freebird.com.tr 0532 123 45 67', 'call_sign' => '12345678950']];
        $result = $this->gate()->table('APM', ['flight_no', 'call_sign'], $rows);

        $this->assertSame(['FH489P [MASKED] [MASKED]', AiDataGate::MASK], $result['rows'][0]);
    }

    public function test_employee_names_from_successfactors_are_kept_unless_disabled(): void
    {
        $columns = ['userNav.firstName', 'userNav.lastName', 'userNav.email'];
        $rows = [['userNav.firstName' => 'Elif', 'userNav.lastName' => 'Kaya', 'userNav.email' => 'elif.kaya@gozendigital.com']];

        $this->assertSame([['Elif', 'Kaya', AiDataGate::MASK]], $this->gate()->table('SuccessFactors', $columns, $rows)['rows']);

        // İstisna sadece SuccessFactors için.
        $this->assertSame([[AiDataGate::MASK, AiDataGate::MASK, AiDataGate::MASK]], $this->gate()->table('IBS', $columns, $rows)['rows']);

        app(AppSettings::class)->set(AppSettings::ALLOW_EMPLOYEE_NAMES, false);
        $this->assertSame([[AiDataGate::MASK, AiDataGate::MASK, AiDataGate::MASK]], $this->gate()->table('SuccessFactors', $columns, $rows)['rows']);
    }

    public function test_partially_masked_email_is_kept(): void
    {
        $this->assertSame([['e***@gozendigital.com']], $this->gate()->table('SuccessFactors', ['userNav.email'], [['userNav.email' => 'e***@gozendigital.com']])['rows']);
    }

    public function test_rows_are_limited_and_total_is_reported(): void
    {
        $rows = array_map(fn (int $i) => ['flight_no' => 'FH'.$i, 'pax' => $i], range(1, 500));

        $result = $this->gate()->table('IBS', ['flight_no', 'pax'], $rows);

        $this->assertCount(200, $result['rows']);
        $this->assertSame(500, $result['total_rows']);
        $this->assertSame(200, $result['returned_rows']);
        $this->assertTrue($result['truncated']);
    }

    public function test_character_limit_is_applied(): void
    {
        $rows = array_map(fn (int $i) => ['reason' => str_repeat('rötar nedeni ', 30), 'n' => $i], range(1, 200));

        $result = $this->gate()->table('IBS', ['reason', 'n'], $rows);

        $this->assertLessThanOrEqual(40000, mb_strlen((string) json_encode(['columns' => $result['columns'], 'rows' => $result['rows']], JSON_UNESCAPED_UNICODE)));
        $this->assertLessThan(200, $result['returned_rows']);
        $this->assertSame(200, $result['total_rows']);
        $this->assertTrue($result['truncated']);
    }

    public function test_nested_structures_are_masked_by_key_and_content(): void
    {
        $result = $this->gate()->structure('APM', ['legs' => [['flightNumber' => 'FH1256', 'crew' => [['firstName' => 'Ahmet', 'phone' => '0532 123 45 67']], 'info' => 'PNR K7X2PQ']]]);

        $this->assertSame(['legs' => [['flightNumber' => 'FH1256', 'crew' => [['firstName' => AiDataGate::MASK, 'phone' => AiDataGate::MASK]], 'info' => 'PNR [MASKED]']]], $result['data']);
        $this->assertSame(3, $result['masked_values']);
    }
}
