<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentTrigger;
use App\Models\AgentVersion;
use App\Models\Conversation;
use App\Models\Execution;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Tasarımdaki örnek ekranlar için demo verisi: bekleyen onay, bugünkü etkinlik ve kaynaklı bir cevap.
 * Sadece yerel ortamda çalışır (APP_ENV=local); canlıda reddedilir. php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public const NAMESPACE = 'fortis.demo';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder sadece yerel ortamda (APP_ENV=local) çalışır.');
        }

        $owner = User::query()->where('role', User::ROLE_ADMIN)->orderBy('id')->firstOrFail();

        $runs = [
            ['morning-brief', 'Morning Brief', 'SUCCESS', '06:00'],
            ['operating-kpi', 'Operating KPI', 'PAUSED', '07:15'],
            ['insurance-notification', 'Insurance Notification', 'PAUSED', '08:41'],
            ['fuel-burn-watch', 'Fuel Burn Watch', 'FAILED', '09:02'],
        ];

        foreach ($runs as [$id, $title, $state, $time]) {
            $flow = Flow::query()->updateOrCreate(
                ['namespace' => self::NAMESPACE, 'flow_id' => $id],
                ['title' => $title, 'summary' => ['Demo verisi'], 'status' => Flow::STATUS_DRAFT, 'owner_id' => $owner->id, 'department_id' => $owner->department_id],
            );
            $version = $flow->versions()->first() ?? FlowVersion::factory()->for($flow)->create(['number' => 1, 'title' => $title]);
            $startedAt = now('Europe/Istanbul')->setTimeFromTimeString($time)->utc();

            Execution::query()->updateOrCreate(['kestra_execution_id' => "demo-{$id}"], [
                'flow_id' => $flow->id,
                'flow_version_id' => $version->id,
                'state' => $state,
                'started_at' => $startedAt,
                'ended_at' => $state === 'PAUSED' ? null : $startedAt->addMinutes(2),
                'triggered_by_user_id' => $owner->id,
            ]);
        }

        $this->agent($owner);

        $conversation = Conversation::query()->firstOrCreate(
            ['title' => '[Demo] Dün hangi rotalar kötüydü?', 'user_id' => $owner->id],
            ['kind' => Conversation::KIND_CHAT, 'title_generated_at' => now()],
        );

        if ($conversation->messages()->exists()) {
            return;
        }

        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => [['type' => 'text', 'text' => 'Dün yük faktörü en düşük rotalar hangileriydi?']], 'text' => 'Dün yük faktörü en düşük rotalar hangileriydi?']);

        $text = "Dün **beş rota** yük faktöründe %82 hedefinin altında kaldı. En büyük açık **AYT–HAJ** hattında: **%68,4** [^q:q_demo00001].\n\n*Hedefin altındaki rotalar · 11 Eki 2026 · tarifeli*\n\n| Rota | Yük faktörü | Hedef | Fark |\n|---|---:|---:|---:|\n| AYT–HAJ | %68,4 | %82,0 | -13,6 |\n| AYT–DUS | %71,2 | %82,0 | -10,8 |\n| AYT–MUC | %74,0 | %82,0 | -8,0 |\n| ADB–STR | %76,5 | %82,0 | -5,5 |\n| DLM–BHX | %77,9 | %82,0 | -4,1 |\n\nOlası nedenler: AYT–HAJ seferinde 42 kişilik bir grup rezervasyonu iptal edilmiş görünüyor [^q:q_demo00002].\n\n*Kaynak: IBS (11 Eki 2026, tarifeli seferler).*\n\n:::actions\n- title: AYT–HAJ için grup iptallerini incele | kind: ask\n- title: Özeti #occ-operasyon kanalına gönder | kind: write | system: Teams\n- title: Bunu her sabah otomatik al | kind: automate\n:::";

        $queries = [
            ['tool' => 'query_warehouse', 'source' => 'IBS', 'purpose' => 'Dünkü seferler, rota bazında yük faktörü', 'query_id' => 'q_demo00001', 'rows' => 212, 'total_rows' => 212, 'masked' => false, 'query' => ['sql' => 'SELECT … FROM ires_dim_flt_leg WHERE …'], 'at' => now()->toIso8601String()],
            ['tool' => 'query_warehouse', 'source' => 'IBS', 'purpose' => 'AYT–HAJ grup rezervasyon iptalleri', 'query_id' => 'q_demo00002', 'rows' => 3, 'total_rows' => 3, 'masked' => true, 'query' => ['sql' => 'SELECT … COUNT(*) …'], 'at' => now()->toIso8601String()],
        ];

        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => [['type' => 'text', 'text' => $text]],
            'text' => $text,
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'meta' => ['answer' => [
                'trace' => [
                    ['id' => 's1', 'kind' => 'scope', 'status' => 'done', 'label' => 'İzin kapsamı', 'detail' => "{$owner->name} · Admin — 3 bağlı kaynaktan 2'si kapsamda", 'sources' => [['key' => 'ibs', 'label' => 'IBS', 'in_scope' => true], ['key' => 'apm', 'label' => 'APM', 'in_scope' => true], ['key' => 'successfactors', 'label' => 'SuccessFactors', 'in_scope' => false]], 'at_ms' => 5],
                    ['id' => 's2', 'kind' => 'model', 'status' => 'done', 'label' => 'Model seçildi · Auto → Claude Sonnet 5.5', 'detail' => 'Genel varsayılan model', 'model' => 'claude-sonnet-5-5', 'auto' => true, 'at_ms' => 6],
                    ['id' => 'tu1', 'kind' => 'query', 'status' => 'done', 'tool' => 'query_warehouse', 'purpose' => $queries[0]['purpose'], 'source' => 'IBS', 'rows' => 212, 'total_rows' => 212, 'query_id' => 'q_demo00001', 'query' => $queries[0]['query'], 'at_ms' => 4200],
                    ['id' => 's4', 'kind' => 'model', 'status' => 'done', 'label' => 'Model seçildi · Auto → Claude Opus 5.5', 'detail' => 'Veri çekme ve çok adımlı karşılaştırma', 'model' => 'claude-opus-5-5', 'auto' => true, 'at_ms' => 4300],
                    ['id' => 'tu2', 'kind' => 'query', 'status' => 'done', 'tool' => 'query_warehouse', 'purpose' => $queries[1]['purpose'], 'source' => 'IBS', 'rows' => 3, 'total_rows' => 3, 'masked' => true, 'query_id' => 'q_demo00002', 'query' => $queries[1]['query'], 'at_ms' => 9800],
                ],
                'queries' => $queries,
                'models' => ['claude-sonnet-5-5', 'claude-opus-5-5'],
                'auto' => true,
                'duration_ms' => 31400,
                'steps' => 5,
                'writes' => [],
                'citation_mismatch' => [],
            ]],
        ]);
    }

    /** Elle çalıştırılabilen pilot agent: dünkü operasyon özeti, e-posta onayla (pilotta sadece sahibine). */
    private function agent(User $owner): void
    {
        $agent = Agent::query()->updateOrCreate(['slug' => 'sabah-operasyon-ozeti'], [
            'name' => 'Sabah Operasyon Özeti',
            'description' => 'Dünkü uçuş sayısı, zamanında kalkış, iptaller ve en uzun rötarları özetleyip e-postayla gönderir',
            'owner_id' => $owner->id,
            'department_id' => $owner->department_id,
            'status' => Agent::STATUS_PILOT,
            'icon' => 'SO',
            'model' => 'auto',
            'instructions' => "Dünkü (Türkiye saatiyle) uçuşları APM'den incele: toplam sefer, iptaller, 15 dakikadan uzun rötarlar ve rötar kodları.\nEn uzun 5 rötarı tablo olarak ver, en sık 3 rötar nedenini açıkla.\nÖzeti OCC yöneticilerine e-posta ile gönder (occ-yonetim@freebird.com.tr). Konu: \"Sabah Operasyon Özeti · {tarih}\".",
            'tools' => [
                ['name' => 'query_apm'],
                ['name' => 'describe_apm'],
                ['name' => 'send_email', 'constraints' => ['max_per_run' => 1]],
            ],
            'approval_policy' => null,
            'suggested_prompts' => ['Dünkü özeti hazırla', 'Sadece AYT çıkışlı seferlere bak'],
        ]);

        AgentVersion::query()->firstOrCreate(['agent_id' => $agent->id, 'number' => 1], [
            'snapshot' => $agent->only(['name', 'description', 'instructions', 'model', 'tools', 'approval_policy']),
            'created_by' => $owner->id,
            'source' => 'manual',
            'note' => 'Demo',
        ]);

        AgentTrigger::query()->firstOrCreate(['agent_id' => $agent->id, 'type' => 'manual'], ['input_template' => 'Dünkü operasyon özetini hazırla ve gönder.']);
    }
}
