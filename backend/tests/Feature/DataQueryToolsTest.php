<?php

namespace Tests\Feature;

use App\Ai\DataQueryTools;
use App\Models\AuditLog;
use App\Services\Claude\FlowTools;
use App\Services\Claude\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDataSources;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DataQueryToolsTest extends TestCase
{
    use FakesDataSources, RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDataSources();
    }

    /** @param  array<string, mixed>  $input */
    private function callTool(string $tool, array $input): ToolResult
    {
        return $this->app->make(FlowTools::class)->execute($tool, $input + ['purpose' => 'Test']);
    }

    /** @return array<string, mixed> */
    private function data(ToolResult $result): array
    {
        return json_decode($result->content, true);
    }

    public function test_every_tool_that_returns_external_data_goes_through_the_gate(): void
    {
        $names = array_column($this->app->make(FlowTools::class)->definitions(), 'name');
        $dataTools = array_values(array_filter($names, fn (string $name) => str_starts_with($name, 'preview_') || str_starts_with($name, 'query_')));

        $this->assertNotEmpty($dataTools);
        $this->assertSame([], array_values(array_diff($dataTools, FlowTools::EXTERNAL_DATA_TOOLS)), 'Bu araçlar AiDataGate\'ten geçmiyor.');
        $this->assertSame(DataQueryTools::TOOLS, array_values(array_intersect($names, DataQueryTools::TOOLS)));
    }

    public function test_warehouse_rejects_writes_multiple_statements_and_missing_limit(): void
    {
        foreach ([
            "INSERT INTO ires_dim_flight_test (flight_no) VALUES ('X') LIMIT 1" => 'SELECT veya WITH',
            "UPDATE ires_dim_flight_test SET dep = 'X' LIMIT 1" => 'UPDATE',
            'SELECT flight_no FROM ires_dim_flight_test LIMIT 1; DELETE FROM ires_dim_flight_test' => 'Tek bir SQL',
            'SELECT flight_no FROM ires_dim_flight_test' => 'LIMIT',
            'SELECT flight_no FROM ires_dim_flight_test LIMIT 5000' => 'LIMIT en fazla 1000',
        ] as $sql => $error) {
            $result = $this->callTool('query_warehouse', ['sql' => $sql]);
            $this->assertTrue($result->isError, $sql);
            $this->assertStringContainsString($error, $this->data($result)['error'], $sql);
        }

        $this->assertSame(1, \DB::table('ires_dim_flight_test')->where('flight_no', 'FH1256')->count());
        $this->assertSame(5, AuditLog::query()->where('action', 'ai.query')->where('result', AuditLog::RESULT_BLOCKED)->count());
    }

    public function test_warehouse_allows_only_counts_on_passenger_tables(): void
    {
        foreach ([
            'SELECT pax_name, email FROM ires_dim_pnr_pax LIMIT 10',
            'SELECT * FROM ires_dim_pnr_pax LIMIT 10',
            'SELECT dep, MAX(pax_name) AS x, COUNT(*) AS n FROM ires_dim_pnr_pax GROUP BY dep LIMIT 10',
            'SELECT pax_name, COUNT(*) AS n FROM ires_dim_pnr_pax GROUP BY pax_name LIMIT 10',
            'SELECT f.flight_no FROM ires_dim_flight_test f JOIN ires_dim_pnr_pax p ON p.dep = f.dep LIMIT 10',
        ] as $sql) {
            $this->assertTrue($this->callTool('query_warehouse', ['sql' => $sql])->isError, $sql);
        }

        $result = $this->callTool('query_warehouse', ['sql' => 'SELECT dep, COUNT(*) AS pax_count FROM ires_dim_pnr_pax GROUP BY dep LIMIT 10']);

        $this->assertFalse($result->isError, $result->content);
        $this->assertSame(['dep', 'pax_count'], $this->data($result)['columns']);
        $this->assertSame([['AYT', 2]], $this->data($result)['rows']);
    }

    public function test_warehouse_query_with_parameters_returns_masked_rows(): void
    {
        $result = $this->callTool('query_warehouse', ['sql' => 'SELECT flight_no, seats_sold, capacity, contact_email FROM ires_dim_flight_test WHERE arr = :arr LIMIT 10', 'parameters' => ['arr' => 'DUS']]);

        $data = $this->data($result);
        $this->assertSame('IBS', $data['source']);
        $this->assertSame([['FH1256', 170, 189, '[MASKED]']], $data['rows']);
        $this->assertSame(['contact_email'], $data['masked_fields']);
        $this->assertStringStartsWith('q_', $data['query_id']);
        $this->assertSame(['tool' => 'query_warehouse', 'source' => 'IBS', 'purpose' => 'Test', 'query_id' => $data['query_id'], 'rows' => 1, 'total_rows' => 1, 'masked' => true], $result->meta);

        $missing = $this->callTool('query_warehouse', ['sql' => 'SELECT flight_no FROM ires_dim_flight_test WHERE arr = :arr LIMIT 10']);
        $this->assertStringContainsString("'arr' parametresi eksik", $missing->content);
    }

    public function test_apm_rejects_endpoints_outside_the_allow_list(): void
    {
        foreach (['/api/crew-profile-data/ABC/contacts', '/api/contacts', '/api/legs/123/pax-brief', '/api/unknown'] as $path) {
            $result = $this->callTool('query_apm', ['path' => $path]);
            $this->assertTrue($result->isError, $path);
            $this->assertStringContainsString('AI sorgusuna açık değil', $result->content);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'contacts'));
    }

    public function test_apm_shaper_returns_flat_rows_without_crew_names(): void
    {
        $result = $this->callTool('query_apm', ['path' => '/api/legs?dateFrom=2026-10-11T00:00:00Z&dateTo=2026-10-12T00:00:00Z&includeCrewMembers=true']);
        $data = $this->data($result);

        $this->assertSame('APM', $data['source']);
        $this->assertSame(['leg_id', 'flight_no', 'dep', 'arr', 'std_utc', 'sta_utc', 'atd_utc', 'ata_utc', 'dep_delay_min', 'delay_codes', 'delay_reasons', 'aircraft_reg', 'aircraft_type', 'cancelled', 'cancel_reason', 'pax_total', 'pax_infant'], $data['columns']);
        $first = array_combine($data['columns'], $data['rows'][0]);
        $this->assertSame('FH1256', $first['flight_no']);
        $this->assertSame(142, $first['dep_delay_min']);
        $this->assertSame('41', $first['delay_codes']);
        $this->assertSame(180, $first['pax_total']);
        $this->assertStringNotContainsString('Kaptan', $result->content);
        $this->assertStringNotContainsString('Veli', $result->content);
        $this->assertSame(2, $data['total_rows']);

        // Ekip üyeleri hiçbir zaman istenmez.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/legs') && ! str_contains($request->url(), 'includeCrewMembers'));
    }

    public function test_successfactors_never_returns_national_id_phone_address_birth_date_or_salary(): void
    {
        $result = $this->callTool('query_successfactors', ['path' => 'EmpJob?$expand=userNav']);
        $data = $this->data($result);

        $this->assertSame(['userId', 'department', 'startDate', 'userNav.firstName', 'userNav.lastName', 'userNav.email'], $data['columns']);
        $this->assertSame(['1001', 'FIN', '2026-09-19', 'Elif', 'Kaya', 'e***@gozendigital.com'], $data['rows'][0]);
        foreach (['12345678950', '0532', 'Atatürk', '85000', 'cellPhone', 'nationalId', 'dateOfBirth', 'salary', 'homeAddress'] as $value) {
            $this->assertStringNotContainsString($value, $result->content);
        }

        foreach (['PerPersonal?$select=nationalId', 'PerPhone', 'PerAddressDEFLT', 'EmpJob?$select=userNav/dateOfBirth', 'EmpPayCompRecurring'] as $path) {
            $this->assertTrue($this->callTool('query_successfactors', ['path' => $path])->isError, $path);
        }
    }

    public function test_successfactors_count_query(): void
    {
        $data = $this->data($this->callTool('query_successfactors', ['path' => "EmpJob/\$count?\$filter=department%20eq%20'FIN'"]));

        $this->assertSame(['count'], $data['columns']);
        $this->assertSame([[3]], $data['rows']);
    }

    public function test_successfactors_grouped_count_counts_every_group_in_one_query(): void
    {
        $page = fn (array $departments, ?string $next) => Http::response(['d' => array_filter([
            'results' => array_map(fn (?string $d) => ['department' => $d, 'departmentNav' => ['name' => $d ? "Dep {$d}" : null]], $departments),
            '__next' => $next,
        ])]);
        // Önceki sahte yanıtlar (fakeDataSources) öncelikli eşleşir; bu test kendi yanıtlarıyla başlar.
        Http::swap(new HttpFactory);
        Http::fake([
            'sf.test/odata/v2/EmpJob?*skiptoken*' => $page(['OPS', 'FIN', null], null),
            'sf.test/odata/v2/EmpJob*' => $page(['OPS', 'OPS', 'FIN', 'CAB'], 'https://sf.test/odata/v2/EmpJob?$skiptoken=2'),
        ]);

        $data = $this->data($this->callTool('query_successfactors', ['path' => "EmpJob?\$filter=emplStatus%20eq%20'A'&\$select=userId", 'group_by' => 'department']));

        $this->assertSame(['department', 'count'], $data['columns']);
        $this->assertSame([['OPS', 3], ['FIN', 2], ['CAB', 1], ['(boş)', 1]], $data['rows']);
        $this->assertSame(7, $data['records_counted']);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), "\$filter=emplStatus eq 'A'") && str_contains($r->url(), '$select=department') && ! str_contains($r->url(), 'userId'));

        // Gezinme alanı: $expand eklenir.
        $this->callTool('query_successfactors', ['path' => 'EmpJob', 'group_by' => 'departmentNav/name']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '$select=departmentNav/name') && str_contains($r->url(), '$expand=departmentNav'));

        // Kişisel alana göre gruplanamaz.
        $this->assertTrue($this->callTool('query_successfactors', ['path' => 'EmpJob', 'group_by' => 'userNav/lastName'])->isError);
        $this->assertTrue($this->callTool('query_successfactors', ['path' => 'EmpJob', 'group_by' => 'department;drop'])->isError);
    }

    public function test_purpose_is_required(): void
    {
        $result = $this->app->make(FlowTools::class)->execute('query_warehouse', ['sql' => 'SELECT 1 LIMIT 1']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('purpose', $result->content);
    }
}
