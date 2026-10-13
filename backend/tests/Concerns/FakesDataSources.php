<?php

namespace Tests\Concerns;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Services\Apm\ApmApi;
use App\Services\Settings\AppSettings;
use App\Services\SuccessFactors\SuccessFactors;
use App\Services\Warehouse\SqlGuard;
use App\Services\Warehouse\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeAnthropic;

/**
 * Veri sorgusu testleri için sahte APM, SuccessFactors ve IBS (test veritabanında tablolar). Sahte veride bilerek
 * kişisel bilgi var (yolcu adı, e-posta, telefon, PNR, bilet no, TC kimlik, mürettebat adı); bunların Claude'a
 * hiçbir koşulda gitmediği test edilir.
 */
trait FakesDataSources
{
    /** Claude'a hiçbir koşulda gitmemesi gereken değerler. */
    protected const PERSONAL_DATA = [
        'Veli Yolcu', 'veli.yolcu@ornek.com', 'K7X2PQ', '0532 123 45 67', '2351234567890', '12345678950',
        'Kaptan Ahmet Demir', 'U12345678', 'Atatürk Cad. No:5', 'mert.aydin@gozendigital.com',
    ];

    protected FakeAnthropic $claude;

    protected function fakeDataSources(string $dataAccess = AppSettings::DATA_ACCESS_MASKED): void
    {
        app(AppSettings::class)->set(AppSettings::DATA_ACCESS, $dataAccess);

        $this->app->singleton(ApmApi::class, fn () => new ApmApi(
            spec: json_decode((string) file_get_contents(config('apm.spec_path')), true),
            kestraBaseUrl: 'https://apm.kestra.test',
            grantType: 'client_credentials',
            allowWrites: false,
            readOnlyPosts: config('apm.read_only_posts'),
            directBaseUrl: 'https://apm.test',
            credentials: ['client_id' => 'id', 'client_secret' => 'secret'],
        ));

        $this->app->singleton(SuccessFactors::class, fn () => new SuccessFactors(
            catalog: json_decode((string) file_get_contents(config('successfactors.requests_path')), true),
            baseUrl: 'https://sf.test/odata/v2/',
            username: 'u',
            password: 'p',
        ));

        Schema::create('ires_dim_flight_test', function (Blueprint $table) {
            $table->string('flight_no');
            $table->string('dep');
            $table->string('arr');
            $table->integer('seats_sold');
            $table->integer('capacity');
            $table->string('remark')->nullable();
            $table->string('contact_email')->nullable();
        });
        DB::table('ires_dim_flight_test')->insert([
            ['flight_no' => 'FH1256', 'dep' => 'AYT', 'arr' => 'DUS', 'seats_sold' => 170, 'capacity' => 189, 'remark' => 'Grup PNR K7X2PQ, iletişim 0532 123 45 67', 'contact_email' => 'veli.yolcu@ornek.com'],
            ['flight_no' => 'FH0812', 'dep' => 'AYT', 'arr' => 'HAJ', 'seats_sold' => 150, 'capacity' => 189, 'remark' => null, 'contact_email' => null],
        ]);

        Schema::create('ires_dim_pnr_pax', function (Blueprint $table) {
            $table->string('pax_name');
            $table->string('email');
            $table->string('pnr');
            $table->string('dep');
        });
        DB::table('ires_dim_pnr_pax')->insert([
            ['pax_name' => 'Veli Yolcu', 'email' => 'veli.yolcu@ornek.com', 'pnr' => 'K7X2PQ', 'dep' => 'AYT'],
            ['pax_name' => 'Ayla Yolcu', 'email' => 'ayla@ornek.com', 'pnr' => 'M3N8RT', 'dep' => 'AYT'],
        ]);

        $this->app->singleton(Warehouse::class, function () {
            $dictionary = json_decode((string) file_get_contents(config('warehouse.dictionary_path')), true);

            return new Warehouse(
                connection: fn () => DB::connection(),
                queries: json_decode((string) file_get_contents(config('warehouse.queries_path')), true),
                dictionary: $dictionary,
                guard: new SqlGuard($dictionary['hidden_columns'], config('warehouse.max_execution_ms'), config('warehouse.max_limit')),
                cacheSeconds: 0,
            );
        });

        $leg = fn (string $number, string $dep, int $delay) => [
            'legId' => 1000 + (int) $number,
            'flightNumberInfo' => ['designator' => 'FH', 'flightNumber' => $number],
            'airportsInfo' => ['scheduledDepartureAirportCode' => $dep, 'scheduledArrivalAirportCode' => 'DUS'],
            'flightTimes' => ['scheduledTimeDeparture' => '2026-10-11T03:40:00Z', 'actualTimeDeparture' => '2026-10-11T06:02:00Z'],
            'delaysInfo' => ['departureDelay1' => ['delayCode' => '41', 'delayDurationInMinutes' => $delay, 'delayReason' => 'Teknik arıza']],
            'aircraftInfo' => ['actualAircraftRegistration' => 'TC-FHB', 'actualAircraftTypeCode' => '738'],
            'statusInfo' => ['cancelled' => false],
            'passengerInfo' => ['paxY' => 180, 'paxInfant' => 2],
            'crewMembers' => [['firstName' => 'Kaptan Ahmet Demir', 'phone' => '0532 123 45 67', 'passport' => 'U12345678']],
            'remarks' => 'Yolcu Veli Yolcu (veli.yolcu@ornek.com, bilet 2351234567890) şikâyetçi',
        ];

        Http::fake([
            'apm.test/oauth/token' => Http::response(['access_token' => 'tok']),
            'apm.test/api/legs*' => Http::response(['page' => 1, 'totalNumberOfItems' => 2, 'legs' => ['content' => [$leg('1256', 'AYT', 142), $leg('0812', 'AYT', 96)]]]),
            'sf.test/odata/v2/EmpJob/$count*' => Http::response('3'),
            'sf.test/odata/v2/*' => Http::response(['d' => ['__count' => '2', 'results' => [
                ['userId' => '1001', 'department' => 'FIN', 'startDate' => '/Date(1789776000000)/', 'userNav' => [
                    'firstName' => 'Elif', 'lastName' => 'Kaya', 'email' => 'elif.kaya@gozendigital.com', 'cellPhone' => '0532 123 45 67',
                    'homeAddress' => 'Atatürk Cad. No:5', 'nationalId' => '12345678950', 'dateOfBirth' => '/Date(631152000000)/',
                ]],
                ['userId' => '1002', 'department' => 'FIN', 'startDate' => '/Date(1790380800000)/', 'userNav' => [
                    'firstName' => 'Mert', 'lastName' => 'Aydın', 'email' => 'mert.aydin@gozendigital.com', 'salary' => '85000',
                ]],
            ]]]),
        ]);
    }

    /** ClaudeChat gerçek SDK ile çalışır; HTTP katmanı sahtedir. */
    protected function fakeClaude(): FakeAnthropic
    {
        $this->claude = new FakeAnthropic;
        $this->app->instance(Client::class, new Client(apiKey: 'test-key', requestOptions: RequestOptions::with(transporter: $this->claude, maxRetries: 0)));

        return $this->claude;
    }
}
