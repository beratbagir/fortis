<?php

namespace Tests\Feature;

use App\Services\Flows\FlowValidator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Doğrulayıcı tek güvenlik katmanı. Buradaki her "reddedilmeli" senaryosu Kestra'ya ulaşmamalı.
 */
class FlowValidatorTest extends TestCase
{
    private const MAIL = <<<'YAML'
            type: io.kestra.plugin.email.MailSend
            host: "{{ secret('SMTP_HOST') }}"
            username: "{{ secret('SMTP_USERNAME') }}"
            password: "{{ secret('SMTP_PASSWORD') }}"
            to: ops@example.com
        YAML;

    /** Sahte Kestra doğrulama yanıtı; testler değiştirebilir. */
    private mixed $kestraResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFlowEnvironment(
            secrets: ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'API_TOKEN'],
            dns: ['rebind.example.com' => ['10.0.0.5'], 'metadata.example.com' => ['169.254.169.254']],
        );

        Http::fake(fn () => $this->kestraResponse ?? Http::response([['constraints' => null]]));
    }

    private function validate(string $yaml, string $mode = FlowValidator::MODE_PROPOSAL): array
    {
        return $this->app->make(FlowValidator::class)->validate($yaml, $mode);
    }

    /** @param  list<string>|string  $tasks */
    private function flow(string $tasks, string $extra = ''): string
    {
        return "id: test-flow\nnamespace: fortis.chat\ntasks:\n{$tasks}\n{$extra}";
    }

    private function assertRejected(string $yaml, string $expected, string $mode = FlowValidator::MODE_PROPOSAL): void
    {
        $result = $this->validate($yaml, $mode);

        $this->assertFalse($result['valid'], "Reddedilmeliydi:\n{$yaml}");
        $this->assertNull($result['canonical_yaml']);
        $this->assertStringContainsString($expected, implode("\n", $result['errors']));
    }

    // ---- Geçerli akışlar -------------------------------------------------

    public function test_valid_flow_passes_and_returns_canonical_yaml(): void
    {
        $result = $this->validate($this->flow(<<<'YAML'
          - id: check
            type: io.kestra.plugin.core.http.Request
            uri: https://example.com/health?x={{ inputs.x }}
            headers:
              Authorization: "Bearer {{ secret('API_TOKEN') }}"
            retry:
              type: constant
              interval: PT1M
              maxAttempts: 3
          - id: mail
        YAML."\n".self::MAIL, "inputs:\n  - id: x\n    type: STRING\n"));

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
        $this->assertSame(['API_TOKEN', 'SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD'], $result['secrets']);
        $this->assertSame([], $result['missing_secrets']);
        Http::assertSent(fn ($request) => $request->body() === $result['canonical_yaml']);
    }

    public function test_all_prompt_examples_are_valid(): void
    {
        $this->requireWarehouse();
        $this->useFlowEnvironment(secrets: ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM', 'SLACK_WEBHOOK_URL', 'TEAMS_WEBHOOK_URL', 'IBS_JDBC_URL', 'IBS_DB_USERNAME', 'IBS_DB_PASSWORD', 'SF_USERNAME', 'SF_PASSWORD', 'APM_CLIENT_ID', 'APM_CLIENT_SECRET']);

        foreach (glob(resource_path('prompts/examples/*.yml')) as $path) {
            $result = $this->validate((string) file_get_contents($path), FlowValidator::MODE_UPLOAD);
            $this->assertTrue($result['valid'], basename($path).': '.implode("\n", $result['errors']));
        }
    }

    public function test_anchors_are_resolved_and_not_sent_to_kestra(): void
    {
        $result = $this->validate(<<<'YAML'
        id: anchors
        namespace: fortis.chat
        tasks:
          - &log
            id: a
            type: io.kestra.plugin.core.log.Log
            message: hi
          - id: b
            type: io.kestra.plugin.core.log.Log
            message: hi
        YAML);

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
        $this->assertStringNotContainsString('&log', $result['canonical_yaml']);
    }

    // ---- Yapı ------------------------------------------------------------

    public function test_required_fields_namespace_and_top_level_keys(): void
    {
        $result = $this->validate("id: 'Bad Id'\nnamespace: company.prod\ntasks: []\npluginDefaults:\n  - type: io.kestra.plugin.core.log.Log\n");

        $this->assertFalse($result['valid']);
        $errors = implode("\n", $result['errors']);
        $this->assertStringContainsString("'pluginDefaults' alanı kullanılamaz", $errors);
        $this->assertStringContainsString('id zorunlu', $errors);
        $this->assertStringContainsString("namespace tam olarak 'fortis.chat'", $errors);
        $this->assertStringContainsString('tasks en az bir görev', $errors);
    }

    public function test_custom_tags_and_duplicate_keys_are_rejected(): void
    {
        $this->assertRejected($this->flow("  - id: a\n    type: !custom io.kestra.plugin.core.log.Log\n    message: x"), 'YAML geçersiz');
        $this->assertRejected($this->flow("  - id: a\n    type: io.kestra.plugin.core.log.Log\n    type: io.kestra.plugin.scripts.shell.Commands"), 'YAML geçersiz');
    }

    public function test_oversized_yaml_is_rejected(): void
    {
        $this->assertRejected($this->flow("  - id: a\n    type: io.kestra.plugin.core.log.Log\n    message: ".str_repeat('x', 100_001)), 'çok büyük');
    }

    // ---- Görev tipleri ---------------------------------------------------

    /** @return array<string, array{string}> */
    public static function hiddenForbiddenTasks(): array
    {
        $shell = "type: io.kestra.plugin.scripts.shell.Commands\n";

        return [
            'if.then' => ["  - id: b\n    type: io.kestra.plugin.core.flow.If\n    condition: x\n    then:\n      - id: s\n        {$shell}"],
            'if.else' => ["  - id: b\n    type: io.kestra.plugin.core.flow.If\n    condition: x\n    then: []\n    else:\n      - id: s\n        {$shell}"],
            'parallel in sequential' => ["  - id: g\n    type: io.kestra.plugin.core.flow.Sequential\n    tasks:\n      - id: p\n        type: io.kestra.plugin.core.flow.Parallel\n        tasks:\n          - id: s\n            {$shell}"],
            'switch case' => ["  - id: sw\n    type: io.kestra.plugin.core.flow.Switch\n    value: x\n    cases:\n      A:\n        - id: s\n          {$shell}"],
            'switch defaults' => ["  - id: sw\n    type: io.kestra.plugin.core.flow.Switch\n    value: x\n    defaults:\n      - id: s\n        {$shell}"],
            'foreach' => ["  - id: fe\n    type: io.kestra.plugin.core.flow.ForEach\n    values: [1]\n    tasks:\n      - id: s\n        {$shell}"],
            'pause tasks' => ["  - id: pz\n    type: io.kestra.plugin.core.flow.Pause\n    tasks:\n      - id: s\n        {$shell}"],
            'task errors' => ["  - id: g\n    type: io.kestra.plugin.core.flow.Sequential\n    tasks: []\n    errors:\n      - id: s\n        {$shell}"],
            'python' => ["  - id: s\n    type: io.kestra.plugin.scripts.python.Script\n    script: print(1)"],
            'docker' => ["  - id: s\n    type: io.kestra.plugin.docker.Run\n    containerImage: alpine"],
            'ssh' => ["  - id: s\n    type: io.kestra.plugin.fs.ssh.Command\n    host: x"],
            'download' => ["  - id: s\n    type: io.kestra.plugin.core.http.Download\n    uri: https://example.com"],
            'case variant' => ["  - id: s\n    type: io.kestra.plugin.core.log.log\n    message: x"],
            'whitespace variant' => ["  - id: s\n    type: ' io.kestra.plugin.core.log.Log'\n    message: x"],
            'legacy alias' => ["  - id: s\n    type: io.kestra.core.tasks.log.Log\n    message: x"],
            'type as list' => ["  - id: s\n    type: [io.kestra.plugin.core.log.Log]\n    message: x"],
        ];
    }

    #[DataProvider('hiddenForbiddenTasks')]
    public function test_forbidden_tasks_are_rejected_at_any_depth(string $tasks): void
    {
        $result = $this->validate($this->flow($tasks));

        $this->assertFalse($result['valid'], $tasks);
        $this->assertMatchesRegularExpression('/tipi izinli değil|type bir metin olmalı/', implode("\n", $result['errors']));
        Http::assertNothingSent();
    }

    public function test_forbidden_types_in_flow_level_errors_finally_and_triggers(): void
    {
        $log = "  - id: ok\n    type: io.kestra.plugin.core.log.Log\n    message: x";

        $this->assertRejected($this->flow($log, "errors:\n  - id: e\n    type: io.kestra.plugin.scripts.python.Script\n"), 'izinli değil');
        $this->assertRejected($this->flow($log, "finally:\n  - id: f\n    type: io.kestra.plugin.scripts.node.Script\n"), 'izinli değil');
        $this->assertRejected($this->flow($log, "triggers:\n  - id: t\n    type: io.kestra.plugin.fs.sftp.Trigger\n"), 'izinli değil');
        $this->assertRejected($this->flow($log, "triggers:\n  - id: t\n    type: io.kestra.plugin.core.trigger.Schedule\n    cron: '0 8 * * *'\n    conditions:\n      - type: io.kestra.plugin.core.condition.Weekend\n"), 'izinli değil');
    }

    public function test_value_types_only_in_their_contexts(): void
    {
        $this->assertRejected($this->flow("  - id: a\n    type: STRING"), "'STRING' tipi burada kullanılamaz");
        $this->assertRejected($this->flow("  - id: a\n    type: io.kestra.plugin.core.log.Log\n    message: x", "inputs:\n  - id: f\n    type: FILE\n"), "'FILE' tipi burada kullanılamaz");
        $this->assertRejected($this->flow("  - id: a\n    type: io.kestra.plugin.core.log.Log\n    message: x\n    retry:\n      type: io.kestra.plugin.scripts.shell.Commands"), 'izinli değil');
    }

    // ---- Görev kuralları -------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function blockedRequests(): array
    {
        return [
            'templated host' => ['"{{ trigger.body.url }}"', 'sabit bir http'],
            'templated host part' => ['"https://{{ inputs.host }}/x"', 'sunucu kısmı ifade'],
            'host suffix template' => ['"https://example.com{{ inputs.x }}/a"', 'sunucu kısmı ifade'],
            'file scheme' => ['file:///etc/passwd', 'sabit bir http'],
            'localhost' => ['http://localhost:8080/api', 'localhost'],
            'loopback ip' => ['http://127.0.0.1/', 'iç ağ'],
            'private ip' => ['http://10.0.0.1/', 'iç ağ'],
            'metadata ip' => ['http://169.254.169.254/metadata/v1', 'iç ağ'],
            'cgnat ip' => ['http://100.64.0.1/', 'iç ağ'],
            'single label host' => ['http://postgres:5432/', 'iç ağ sunucu adı'],
            'decimal ip' => ['http://2130706433/', 'iç ağ sunucu adı'],
            'hex ip' => ['http://0x7f.0.0.1/', 'iç ağ sunucu adı'],
            'internal tld' => ['http://db.internal/', 'iç ağ sunucu adı'],
            'ipv6' => ['http://[::1]/', 'IPv6'],
            'credentials' => ['https://user:pass@example.com/', 'kullanıcı adı'],
            'kestra itself' => ['http://kestra.test/api/v1/main/flows', 'sistemin kendi sunucusu'],
            'dns to private' => ['https://rebind.example.com/', 'iç ağ adresine çözümleniyor'],
            'dns to metadata' => ['https://metadata.example.com/', 'iç ağ adresine çözümleniyor'],
        ];
    }

    #[DataProvider('blockedRequests')]
    public function test_http_request_targets_are_restricted(string $uri, string $expected): void
    {
        $this->assertRejected($this->flow("  - id: r\n    type: io.kestra.plugin.core.http.Request\n    uri: {$uri}"), $expected);
    }

    public function test_http_proxy_is_rejected(): void
    {
        $this->assertRejected($this->flow("  - id: r\n    type: io.kestra.plugin.core.http.Request\n    uri: https://example.com\n    options:\n      proxy:\n        address: 10.0.0.1"), 'options.proxy');
    }

    public function test_mail_connection_must_come_from_secrets(): void
    {
        $this->assertRejected($this->flow("  - id: m\n".str_replace("\"{{ secret('SMTP_HOST') }}\"", 'smtp.example.com', self::MAIL)), 'host sadece bir secret');
        $this->assertRejected($this->flow("  - id: m\n    type: io.kestra.plugin.email.MailSend\n    to: a@b.com"), 'host zorunlu');
        $this->assertRejected($this->flow("  - id: m\n".self::MAIL."\n    attachments:\n      - uri: kestra:///other/file"), 'attachments kullanılamaz');
    }

    public function test_chat_webhooks_must_come_from_secrets(): void
    {
        $this->assertRejected($this->flow("  - id: s\n    type: io.kestra.plugin.slack.notifications.SlackIncomingWebhook\n    url: https://hooks.slack.com/services/T0/B0/XYZ\n    messageText: hi"), 'url sadece bir secret');
    }

    public function test_subflow_and_kv_cannot_leave_namespace(): void
    {
        $this->assertRejected($this->flow("  - id: s\n    type: io.kestra.plugin.core.flow.Subflow\n    namespace: company.finance\n    flowId: payroll"), 'alt akış sadece');
        $this->assertRejected($this->flow("  - id: k\n    type: io.kestra.plugin.core.kv.Get\n    key: x\n    namespace: company.finance"), 'namespace verilemez');
    }

    public function test_webhook_key_must_be_long_and_random_looking(): void
    {
        $log = "  - id: ok\n    type: io.kestra.plugin.core.log.Log\n    message: x";

        $this->assertRejected($this->flow($log, "triggers:\n  - id: w\n    type: io.kestra.plugin.core.trigger.Webhook\n    key: abc\n"), 'webhook key');
        $this->assertRejected($this->flow($log, "triggers:\n  - id: w\n    type: io.kestra.plugin.core.trigger.Webhook\n    key: \"{{ inputs.k }}aaaaaaaaaaaaaaaaaaaaaaaa\"\n"), 'webhook key');
    }

    // ---- Şablonlar -------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function riskyTemplates(): array
    {
        return [
            'env vars' => ['{{ envs.DATABASE_URL }}', "'envs' değişkeni"],
            'env vars bracket' => ["{{ envs['X'] }}", "'envs' değişkeni"],
            'globals' => ['{{ globals.token }}', "'globals' değişkeni"],
            'set alias' => ['{% set e = envs %}{{ e }}', "'envs' değişkeni"],
            'render' => ['{{ render(trigger.body.template) }}', "'render()' fonksiyonu"],
            'read storage' => ["{{ read('kestra:///x') }}", "'read()' fonksiyonu"],
            'kv other namespace' => ["{{ kv('k', 'company.finance') }}", "'kv()' fonksiyonu"],
            'http function' => ["{{ http('http://10.0.0.1') }}", "'http()' fonksiyonu"],
            'print context' => ['{{ printContext() }}', "'printContext()' fonksiyonu"],
            'method call' => ['{{ "".getClass().forName("java.lang.Runtime") }}', 'metot çağrısı'],
            'method on output' => ['{{ outputs.a.body.getBytes() }}', 'metot çağrısı'],
            'unknown filter' => ['{{ outputs.a.body | evil }}', "'evil' filtresi"],
            'import tag' => ['{% import "x" %}', "'{% import %}' etiketi"],
            'include tag' => ['{% include "x" %}', "'{% include %}' etiketi"],
            'private context' => ['{{ _context }}', "'_context' değişkeni"],
            'dynamic secret' => ['{{ secret(trigger.body.name) }}', 'secret() sadece sabit'],
            'unclosed' => ['{{ outputs.a', 'kapanmamış ifade'],
            'stray close' => ['abc }}', 'eşleşmeyen'],
        ];
    }

    #[DataProvider('riskyTemplates')]
    public function test_risky_template_expressions_are_rejected(string $expression, string $expected): void
    {
        $value = json_encode($expression, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertRejected($this->flow("  - id: l\n    type: io.kestra.plugin.core.log.Log\n    message: {$value}"), $expected);
    }

    public function test_safe_template_expressions_pass(): void
    {
        $message = json_encode(
            "{{ outputs.a.body | jq('.price') | first }} {{ now() | date('yyyy-MM-dd') }} {{ trigger.body.amount > 10000 ? 'büyük' : 'küçük' }} "
            ."{% if inputs.x is defined %}{{ inputs.x | upper | default('yok') }}{% endif %} {{ taskrun.value }} {{ execution.id }} 'envs' metni "
            .'{{ outputs.p[taskrun.value] is defined and (outputs.p[taskrun.value].values.t | number) > 5 or not (inputs.x is null) }} '
            .'{{ outputs.f[parent.taskrun.value].code }}',
            JSON_UNESCAPED_UNICODE,
        );

        $result = $this->validate($this->flow("  - id: l\n    type: io.kestra.plugin.core.log.Log\n    message: {$message}"));

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
    }

    // ---- Gizli bilgiler --------------------------------------------------

    /** @return array<string, array{string}> */
    public static function plaintextSecrets(): array
    {
        return [
            'password key' => ['    password: hunter2'],
            'token key' => ['    token: abc123'],
            'authorization header' => ["    headers:\n      Authorization: Basic dXNlcjpwYXNz"],
            'anthropic key' => ['    message: sk-ant-api03-abcdefghijklmnop'],
            'aws key' => ['    message: AKIAABCDEFGHIJKLMNOP'],
            'github token' => ['    message: ghp_abcdefghijklmnopqrstuvwxyz0123456789'],
            'slack token' => ['    message: xoxb-1234567890-abcdefghij'],
            'slack webhook' => ['    message: https://hooks.slack.com/services/T000/B000/XXXX'],
            'teams webhook' => ['    message: https://acme.webhook.office.com/webhookb2/abc'],
            'private key' => ['    message: "-----BEGIN RSA PRIVATE KEY-----"'],
            'jwt' => ['    message: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sig'],
            'bearer literal' => ["    message: 'Bearer abcdefghijklmnop1234'"],
            'url credentials' => ['    message: https://admin:secretpw@example.com'],
        ];
    }

    #[DataProvider('plaintextSecrets')]
    public function test_plaintext_secrets_are_rejected(string $property): void
    {
        $this->assertRejected($this->flow("  - id: l\n    type: io.kestra.plugin.core.log.Log\n{$property}"), 'açık');
    }

    public function test_undefined_secrets_warn_in_proposal_and_block_upload(): void
    {
        $yaml = $this->flow("  - id: r\n    type: io.kestra.plugin.core.http.Request\n    uri: https://example.com\n    headers:\n      Authorization: \"Bearer {{ secret('UNKNOWN_TOKEN') }}\"");

        $proposal = $this->validate($yaml);
        $this->assertTrue($proposal['valid']);
        $this->assertSame(['UNKNOWN_TOKEN'], $proposal['missing_secrets']);

        $this->assertRejected($yaml, 'UNKNOWN_TOKEN', FlowValidator::MODE_UPLOAD);
    }

    // ---- Kestra doğrulaması ----------------------------------------------

    public function test_kestra_constraints_are_errors(): void
    {
        $this->kestraResponse = Http::response([['constraints' => 'tasks[0].message: must not be null']]);

        $this->assertRejected($this->flow("  - id: l\n    type: io.kestra.plugin.core.log.Log"), 'must not be null');
    }

    public function test_unreachable_kestra_warns_in_proposal_and_blocks_upload(): void
    {
        $this->kestraResponse = Http::response('', 503);
        $yaml = $this->flow("  - id: l\n    type: io.kestra.plugin.core.log.Log\n    message: x");

        $this->assertTrue($this->validate($yaml)['valid']);
        $this->assertRejected($yaml, 'Kestra doğrulaması yapılamadı', FlowValidator::MODE_UPLOAD);
    }
}
