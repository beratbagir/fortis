<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Claude\FlowTools;
use App\Services\Flows\FlowValidator;
use App\Services\Warehouse\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gerçek IBS veri ambarına bağlanır (sadece okuma). VPN kapalıysa testler atlanır.
 */
class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->requireWarehouse();
        $this->useFlowEnvironment(secrets: ['IBS_JDBC_URL', 'IBS_DB_USERNAME', 'IBS_DB_PASSWORD']);
        Http::fake(['kestra.test/api/v1/main/flows/validate' => Http::response([['constraints' => null]])]);
    }

    /** @return array<string, array{string}> */
    public static function namedQueries(): array
    {
        $queries = json_decode((string) file_get_contents(__DIR__.'/../../resources/warehouse/queries.json'), true)['queries'];

        return array_combine(array_column($queries, 'name'), array_map(fn ($q) => [$q['name']], $queries));
    }

    #[DataProvider('namedQueries')]
    public function test_named_query_passes_guard_explain_and_returns_rows(string $name): void
    {
        $query = collect($this->warehouse->queries())->firstWhere('name', $name);

        $this->assertSame([], $this->warehouse->guard()->check($query['sql'], requireLimit: true)['errors']);
        $this->assertSame(['ok' => true, 'error' => null, 'warnings' => []], $this->warehouse->explain($query['sql']));

        $yesterday = now()->subDay()->toDateString();
        $params = [];
        foreach ($query['parameters'] as $parameter) {
            $params[$parameter['name']] = $parameter['name'] === 'baslangic' ? now()->subDays(3)->toDateString() : $yesterday;
        }

        $rows = DB::connection('ibs')->select($query['sql'], $params);

        $this->assertNotEmpty($rows, "{$name} dün için satır döndürmedi.");
        $this->assertSame($query['columns'], array_keys((array) $rows[0]));
    }

    public function test_tables_hide_backups_and_describe_hides_sensitive_columns(): void
    {
        $tables = array_column($this->warehouse->tables(), null, 'name');

        $this->assertArrayHasKey('ires_fact_pnr_sales', $tables);
        $this->assertNotNull($tables['ires_fact_pnr_sales']['description']);
        $this->assertArrayNotHasKey('ires_fact_pnr_sales_090124', $tables);
        $this->assertArrayNotHasKey('ires_dim_currency_rate_hist_bkp_14aug2026', $tables);

        $described = $this->warehouse->describe(['ires_dim_agency_profile', 'ires_fact_pnr_sales_090124']);
        $columns = array_column($described['ires_dim_agency_profile']['columns'], 'name');

        $this->assertContains('agency_name', $columns);
        $this->assertNotContains('creditcard_number', $columns);
        $this->assertNotContains('iban_num', $columns);
        $this->assertArrayHasKey('error', $described['ires_fact_pnr_sales_090124']);
    }

    public function test_explain_catches_unknown_columns_and_warns_on_full_scans(): void
    {
        $bad = $this->warehouse->explain('SELECT /*+ MAX_EXECUTION_TIME(1000) */ olmayan_sutun FROM ires_dim_flight LIMIT 1');
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString("Unknown column 'olmayan_sutun'", $bad['error']);

        $slow = $this->warehouse->explain('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM ires_fact_ticket_sales WHERE transaction_date_gmt > :t');
        $this->assertTrue($slow['ok']);
        $this->assertStringContainsString('tam tarama', $slow['warnings'][0]);
    }

    private function flow(string $task): string
    {
        return "id: dw-flow\nnamespace: fortis.chat\ntasks:\n{$task}";
    }

    private function queryTask(string $sql, string $extra = "    parameters:\n      gun: \"{{ now() | date('yyyy-MM-dd') }}\"\n", string $fetch = 'FETCH'): string
    {
        return "  - id: q\n    type: io.kestra.plugin.jdbc.mysql.Query\n    url: \"{{ secret('IBS_JDBC_URL') }}\"\n    username: \"{{ secret('IBS_DB_USERNAME') }}\"\n    password: \"{{ secret('IBS_DB_PASSWORD') }}\"\n    fetchType: {$fetch}\n    sql: ".json_encode($sql)."\n".$extra;
    }

    public function test_valid_free_sql_flow_passes(): void
    {
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(60000) */ COUNT(*) AS n FROM ires_fact_ticket_sales WHERE modified_date_ltc_id = (SELECT date_id FROM ires_dim_date WHERE actual_date = :gun) LIMIT 10';

        $result = $this->app->make(FlowValidator::class)->validate($this->flow($this->queryTask($sql)), FlowValidator::MODE_UPLOAD);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
        $this->assertSame([], $result['warnings']);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function rejectedTasks(): array
    {
        $ok = 'SELECT /*+ MAX_EXECUTION_TIME(60000) */ flight_number FROM ires_dim_flight WHERE flight_date = :gun LIMIT 10';
        $params = "    parameters:\n      gun: \"{{ now() | date('yyyy-MM-dd') }}\"\n";

        return [
            'unknown column (EXPLAIN)' => [str_replace('flight_number', 'ucus_no', $ok), $params, 'FETCH', "Unknown column 'ucus_no'"],
            'template in sql' => ["SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1 FROM ires_dim_flight WHERE flight_date = '{{ inputs.d }}' LIMIT 1", '', 'FETCH', '{{ }}'],
            'missing parameter' => [$ok, '', 'FETCH', 'değeri yok: :gun'],
            'unused parameter' => [$ok, $params."      fazla: x\n", 'FETCH', 'kullanılmıyor: fazla'],
            'store fetch type' => [$ok, $params, 'STORE', 'fetchType'],
            'after sql' => [$ok, $params."    afterSQL: DELETE FROM ires_dim_flight\n", 'FETCH', 'afterSQL kullanılamaz'],
            'delete' => ['DELETE FROM ires_dim_flight', '', 'FETCH', 'SELECT veya WITH'],
        ];
    }

    #[DataProvider('rejectedTasks')]
    public function test_warehouse_task_rules(string $sql, string $extra, string $fetch, string $expected): void
    {
        $result = $this->app->make(FlowValidator::class)->validate($this->flow($this->queryTask($sql, $extra, $fetch)), FlowValidator::MODE_UPLOAD);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString($expected, implode("\n", $result['errors']));
    }

    public function test_connection_must_come_from_secrets(): void
    {
        $task = str_replace("password: \"{{ secret('IBS_DB_PASSWORD') }}\"", 'password: hunter2', $this->queryTask('SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1 LIMIT 1', ''));

        $result = $this->app->make(FlowValidator::class)->validate($this->flow($task));

        $this->assertStringContainsString('password sadece bir secret', implode("\n", $result['errors']));
    }

    public function test_card_distinguishes_named_query_from_free_sql(): void
    {
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn(Conversation::factory()->create());
        $named = collect($this->warehouse->queries())->firstWhere('name', 'gunluk_satis_ozeti');
        $params = "    parameters:\n      baslangic: \"{{ now() | dateAdd(-1, 'DAYS') | date('yyyy-MM-dd') }}\"\n      bitis: \"{{ now() | dateAdd(-1, 'DAYS') | date('yyyy-MM-dd') }}\"\n";

        $card = $tools->execute('propose_flow', ['title' => 'Satış', 'summary' => ['x'], 'yaml' => $this->flow($this->queryTask($named['sql'], $params))])->card;
        $this->assertSame('Veri ambarında sorgu çalıştırıyor', $card['warned_steps'][0]['label']);
        $this->assertSame('Onaylı sorgu: Günlük PNR satış özeti', $card['warned_steps'][0]['details']);

        $free = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) AS n FROM ires_dim_flight WHERE flight_date = :gun LIMIT 1';
        $card = $tools->execute('propose_flow', ['title' => 'Uçuş', 'summary' => ['x'], 'yaml' => str_replace('dw-flow', 'dw-flow-2', $this->flow($this->queryTask($free)))])->card;
        $this->assertStringStartsWith('Serbest SQL: SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*)', $card['warned_steps'][0]['details']);
    }

    public function test_tools_return_queries_and_schema(): void
    {
        $tools = $this->app->make(FlowTools::class);

        $queries = json_decode($tools->execute('get_queries', [])->content, true);
        $this->assertCount(count($this->warehouse->queries()), $queries['queries']);
        $this->assertSame(['IBS_JDBC_URL', 'IBS_DB_USERNAME', 'IBS_DB_PASSWORD'], $queries['required_secrets']);

        $list = json_decode($tools->execute('describe_warehouse', [])->content, true);
        $this->assertNotEmpty($list['notes']);
        $this->assertContains('ires_fact_ticket_sales', array_column($list['tables'], 'name'));

        $details = json_decode($tools->execute('describe_warehouse', ['tables' => ['ires_fact_ticket_sales']])->content, true);
        $this->assertContains('modified_date_ltc_id', array_column($details['tables']['ires_fact_ticket_sales']['columns'], 'name'));
        $this->assertNotEmpty($details['tables']['ires_fact_ticket_sales']['indexes']);

        $this->assertTrue($tools->execute('describe_warehouse', ['tables' => array_fill(0, 9, 'x')])->isError);
    }
}
