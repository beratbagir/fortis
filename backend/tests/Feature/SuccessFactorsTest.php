<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\Claude\FlowTools;
use App\Services\Flows\FlowValidator;
use App\Services\SuccessFactors\SuccessFactors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SuccessFactors kuralları. Şema testleri gerçek SuccessFactors'a bağlanır (sadece okuma); ulaşılamazsa atlanır.
 */
class SuccessFactorsTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH = "    options:\n      auth:\n        type: BASIC\n        username: \"{{ secret('SF_USERNAME') }}\"\n        password: \"{{ secret('SF_PASSWORD') }}\"\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(secrets: ['SF_USERNAME', 'SF_PASSWORD']);
        Http::preventStrayRequests(false);
    }

    private function fakeKestra(): void
    {
        Http::fake(['kestra.test/api/v1/main/flows/validate' => Http::response([['constraints' => null]]), '*' => Http::response('', 500)]);
    }

    private function flow(string $uri, string $extra = self::AUTH, string $method = 'GET'): string
    {
        return "id: sf-flow\nnamespace: fortis.chat\ntasks:\n  - id: sf\n    type: io.kestra.plugin.core.http.Request\n    uri: ".json_encode($uri)."\n    method: {$method}\n{$extra}";
    }

    private function validate(string $yaml): array
    {
        return $this->app->make(FlowValidator::class)->validate($yaml, FlowValidator::MODE_UPLOAD);
    }

    public function test_named_requests_are_valid_flows(): void
    {
        $this->fakeKestra();

        foreach ($this->app->make(SuccessFactors::class)->requests() as $request) {
            $uri = str_replace(['{since}', '{userId}', '{from}', '{to}', '{company}', '{name}'], ["{{ now() | dateAdd(-1, 'DAYS') | date('yyyy-MM-dd') }}T00:00:00", '3882', "{{ now() | date('yyyy-MM-dd') }}", "{{ now() | dateAdd(6, 'DAYS') | date('yyyy-MM-dd') }}", 'GDA', 'Y%C4%B1lmaz'], $request['url']);
            $result = $this->validate($this->flow($uri));

            $this->assertTrue($result['valid'], $request['name'].': '.implode("\n", $result['errors']));
        }
    }

    public function test_national_id_cannot_be_requested(): void
    {
        $this->fakeKestra();

        foreach ([
            'https://api55.sapsf.eu/odata/v2/PerNationalId?$format=JSON',
            'https://api55.sapsf.eu/odata/v2/PerPerson?$format=JSON&$expand=nationalIdNav',
            'https://api55.sapsf.eu/odata/v2/EmpJob?$format=JSON&$select=employmentNav/personNav/%6EationalIdNav/nationalId',
        ] as $uri) {
            $this->assertStringContainsString('TC kimlik', implode("\n", $this->validate($this->flow($uri))['errors']), $uri);
        }

        $this->assertSame(['error' => 'TC kimlik bilgisi Fortis\'te kullanılamaz.'], $this->app->make(SuccessFactors::class)->describe('PerNationalId'));
    }

    public function test_preview_returns_cleaned_sample_and_errors(): void
    {
        $base = $this->app->make(SuccessFactors::class)->baseUrl();
        Http::fake([
            $base.'EmpJob*' => Http::response(['d' => ['__count' => '98', 'results' => [
                ['__metadata' => ['uri' => 'x'], 'userId' => '7', 'note' => 'TC 10000000146', 'userNav' => ['firstName' => 'Ali', '__metadata' => []], 'positionNav' => ['__deferred' => ['uri' => 'y']]],
            ]]]),
            $base.'Nope*' => Http::response(['error' => ['message' => ['value' => '[COE0021]Invalid property names']]], 400),
        ]);
        $tools = $this->app->make(FlowTools::class);

        $preview = json_decode($tools->execute('preview_successfactors', ['path' => "EmpJob?\$filter=company%20eq%20'GDA'&\$top=500"])->content, true);
        $this->assertSame(98, $preview['total']);
        $this->assertSame(['userId' => '7', 'note' => 'TC ***********', 'userNav' => ['firstName' => 'Ali']], $preview['records'][0]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '$top=5') && ! str_contains($request->url(), '$top=500') && str_contains($request->url(), '$inlinecount=allpages'));

        $this->assertStringContainsString('Invalid property names', $tools->execute('preview_successfactors', ['path' => 'Nope?$select=x'])->content);
        $this->assertStringContainsString('şablon', $tools->execute('preview_successfactors', ['path' => "EmpJob?\$filter=startDate%20ge%20datetime'{{ now() }}'"])->content);
        $this->assertStringContainsString('TC kimlik', $tools->execute('preview_successfactors', ['path' => 'PerNationalId'])->content);
    }

    public function test_writes_are_rejected_while_disabled(): void
    {
        $this->fakeKestra();

        $result = $this->validate($this->flow('https://api55.sapsf.eu/odata/v2/upsert/?$format=JSON', self::AUTH."    body: '{}'\n", 'POST'));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('sadece okuma (GET)', implode("\n", $result['errors']));
    }

    public function test_credentials_must_be_secrets_and_format_json_required(): void
    {
        $this->fakeKestra();
        $uri = 'https://api55.sapsf.eu/odata/v2/FOLocation?$format=JSON';

        $this->assertStringContainsString('options.auth', implode("\n", $this->validate($this->flow($uri, ''))['errors']));
        $this->assertStringContainsString('password', implode("\n", $this->validate($this->flow($uri, str_replace("\"{{ secret('SF_PASSWORD') }}\"", 'hunter2', self::AUTH)))['errors']));
        $this->assertStringContainsString('username sadece bir secret', implode("\n", $this->validate($this->flow($uri, str_replace("\"{{ secret('SF_USERNAME') }}\"", 'gzn_integration@x', self::AUTH)))['errors']));
        $this->assertStringContainsString('$format=JSON', implode("\n", $this->validate($this->flow('https://api55.sapsf.eu/odata/v2/FOLocation'))['errors']));
        $this->assertTrue($this->validate($this->flow('https://api55.sapsf.eu/odata/v2/FOLocation/$count'))['valid']);
    }

    public function test_legacy_password_and_proxy_options_are_rejected_for_any_request(): void
    {
        $this->fakeKestra();

        $legacy = $this->validate($this->flow('https://example.com/x', "    options:\n      basicAuthUser: u\n      basicAuthPassword: hunter2\n"));
        $this->assertStringContainsString("'basicAuthPassword' değeri açık yazılamaz", implode("\n", $legacy['errors']));

        $proxy = $this->validate($this->flow('https://example.com/x', "    options:\n      proxyAddress: 10.0.0.1\n      proxyPort: 8080\n"));
        $this->assertStringContainsString('options.proxyAddress kullanılamaz', implode("\n", $proxy['errors']));
    }

    public function test_card_marks_successfactors_reads_named_requests_and_personal_data(): void
    {
        $this->fakeKestra();
        $tools = $this->app->make(FlowTools::class);
        $tools->startTurn(Conversation::factory()->create());
        $sf = $this->app->make(SuccessFactors::class);
        $named = collect($sf->requests())->firstWhere('name', 'sf_calisan_listesi');
        $uri = str_replace('{since}', "{{ now() | date('yyyy-MM-dd') }}T00:00:00", $named['url']);

        $card = $tools->execute('propose_flow', ['title' => 'İK', 'summary' => ['x'], 'yaml' => $this->flow($uri)])->card;
        $this->assertSame("SuccessFactors'tan (İK) veri okuyor", $card['warned_steps'][0]['label']);
        $this->assertSame('Hazır istek: Çalışan listesi (EmpJob, ayrıntılı) (kişisel veri içerir)', $card['warned_steps'][0]['details']);

        $card = $tools->execute('propose_flow', ['title' => 'İK 2', 'summary' => ['x'], 'yaml' => str_replace('sf-flow', 'sf-flow-2', $this->flow('https://api55.sapsf.eu/odata/v2/PerEmail?$format=JSON&$top=5'))])->card;
        $this->assertSame('Serbest istek: GET PerEmail', $card['warned_steps'][0]['details']);
    }

    public function test_schema_comes_from_real_successfactors(): void
    {
        $described = $this->app->make(SuccessFactors::class)->describe('EmpJob');

        if ($described === null) {
            $this->markTestSkipped('SuccessFactors şemasına ulaşılamıyor.');
        }

        $this->assertContains('userId', array_column($described['properties'], 'name'));
        $this->assertContains('departmentNav', $described['navigations']);
        $this->assertNotNull(collect($described['properties'])->firstWhere('name', 'userId')['label']);
        $this->assertArrayHasKey('error', $this->app->make(SuccessFactors::class)->describe('BoyleBirVarlikYok'));

        $overview = json_decode($this->app->make(FlowTools::class)->execute('describe_successfactors', [])->content, true);
        $this->assertFalse($overview['writes_allowed']);
        $this->assertCount(20, $overview['requests']);
    }
}
