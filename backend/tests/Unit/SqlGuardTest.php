<?php

namespace Tests\Unit;

use App\Services\Warehouse\SqlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    private const OK = 'SELECT /*+ MAX_EXECUTION_TIME(60000) */ d.actual_date, COUNT(*) FROM ires_fact_pnr_sales s JOIN ires_dim_date d ON d.date_id = s.modified_date_ltc_id WHERE s.modified_date_ltc_id = :gun GROUP BY d.actual_date LIMIT 100';

    private function guard(): SqlGuard
    {
        return new SqlGuard(['creditcard_number', 'iban_num'], 120000, 5000);
    }

    public function test_valid_select_passes_and_lists_placeholders(): void
    {
        $result = $this->guard()->check(self::OK, requireLimit: true);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['gun'], $result['placeholders']);
    }

    public function test_with_queries_and_trailing_semicolon_are_allowed(): void
    {
        $sql = 'WITH x AS (SELECT 1 AS a) SELECT /*+ MAX_EXECUTION_TIME(1000) */ a FROM x LIMIT 1;';

        $this->assertSame([], $this->guard()->check($sql, requireLimit: true)['errors']);
    }

    /** @return array<string, array{string, string}> */
    public static function rejected(): array
    {
        $hint = 'SELECT /*+ MAX_EXECUTION_TIME(1000) */';

        return [
            'no hint' => ['SELECT a FROM t LIMIT 1', 'süre sınırı'],
            'hint too large' => ['SELECT /*+ MAX_EXECUTION_TIME(999999) */ a FROM t LIMIT 1', 'en fazla 120000'],
            'no limit' => ["{$hint} a FROM t", 'LIMIT'],
            'limit too large' => ["{$hint} a FROM t LIMIT 100000", 'en fazla 5000'],
            'template' => ["{$hint} a FROM t WHERE x = '{{ inputs.x }}' LIMIT 1", '{{ }}'],
            'delete' => ['DELETE FROM t', 'SELECT veya WITH'],
            'with delete' => ['WITH x AS (SELECT 1) DELETE FROM t', 'DELETE'],
            'update in subquery' => ["{$hint} a FROM t WHERE 1 = (UPDATE t SET a = 1) LIMIT 1", 'UPDATE'],
            'two statements' => ["{$hint} 1 LIMIT 1; DROP TABLE t", 'Tek bir SQL'],
            'outfile' => ["{$hint} a FROM t INTO OUTFILE '/tmp/x' LIMIT 1", 'INTO OUTFILE'],
            'into variable' => ["{$hint} a INTO @v FROM t LIMIT 1", 'INTO @'],
            'for update' => ["{$hint} a FROM t LIMIT 1 FOR UPDATE", 'FOR UPDATE'],
            'sleep' => ["{$hint} SLEEP(100) LIMIT 1", 'SLEEP'],
            'benchmark' => ["{$hint} BENCHMARK(1000000, MD5(1)) LIMIT 1", 'BENCHMARK'],
            'load file' => ["{$hint} LOAD_FILE('/etc/passwd') LIMIT 1", 'LOAD_FILE'],
            'hidden column' => ["{$hint} creditcard_number FROM ires_dim_agency_profile LIMIT 1", 'creditcard_number'],
            'hidden in comment trick' => ["{$hint} a /* x */ ; /* y */ DROP TABLE t", 'Tek bir SQL'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejected_sql(string $sql, string $expected): void
    {
        $errors = $this->guard()->check($sql, requireLimit: true)['errors'];

        $this->assertNotSame([], $errors, $sql);
        $this->assertStringContainsString($expected, implode("\n", $errors));
    }

    public function test_keywords_inside_strings_and_comments_are_ignored(): void
    {
        $sql = "SELECT /*+ MAX_EXECUTION_TIME(1000) */ 'DELETE; DROP' AS label, a -- UPDATE yorum\nFROM t /* INSERT */ WHERE b = 'x:y' LIMIT 1";

        $result = $this->guard()->check($sql, requireLimit: true);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['placeholders']);
    }

    public function test_fetch_one_does_not_need_limit(): void
    {
        $this->assertSame([], $this->guard()->check('SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM t', requireLimit: false)['errors']);
    }

    public function test_positional_replaces_placeholders_outside_strings(): void
    {
        [$sql, $names] = $this->guard()->positional("SELECT :a, ':b', \"x:c\", `col:d`, :e, '10:30' FROM t WHERE x = :a");

        $this->assertSame("SELECT ?, ':b', \"x:c\", `col:d`, ?, '10:30' FROM t WHERE x = ?", $sql);
        $this->assertSame(['a', 'e', 'a'], $names);
    }

    public function test_normalize_ignores_whitespace_and_semicolon(): void
    {
        $this->assertSame(SqlGuard::normalize("SELECT  a\n FROM t;"), SqlGuard::normalize('select a from t'));
    }
}
