<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentTrigger;
use Illuminate\Database\Seeder;

/**
 * Havacılık agent şablonları (A3.3). Sadece Fortis'e bağlanabilen sistemlerle (APM, IBS, SuccessFactors, e-posta,
 * Teams) çalışanlar. Şablondan kurulunca agent sahibine taslak olarak kopyalanır. Tekrar çalıştırılabilir.
 */
class AgentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $key => $template) {
            $agent = Agent::withTrashed()->updateOrCreate(['template_key' => $key, 'status' => Agent::STATUS_TEMPLATE], [
                'slug' => "sablon-{$key}",
                'name' => $template['name'],
                'description' => $template['description'],
                'icon' => $template['icon'],
                'instructions' => $template['instructions'],
                'model' => 'auto',
                'tools' => $template['tools'],
                'approval_policy' => ['approvers' => ['owner']] + collect($template['tools'])->filter(fn (array $t) => (config('agents.tools')[$t['name']]['kind'] ?? null) === 'write')
                    ->mapWithKeys(fn (array $t) => [$t['name'] => ['mode' => 'always']])->all(),
                'plan' => $template['plan'],
                'suggested_prompts' => $template['suggested_prompts'],
                'owner_id' => null,
                'deleted_at' => null,
            ]);

            $agent->triggers()->delete();
            $agent->triggers()->create(['type' => AgentTrigger::MANUAL]);
            $agent->triggers()->create($template['trigger'] + ['enabled' => true]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function templates(): array
    {
        return [
            'morning-brief' => [
                'name' => 'Sabah Operasyon Özeti',
                'icon' => 'SO',
                'description' => 'Dünkü uçuş sayısını, zamanında kalkışı, iptalleri ve en uzun rötarları nedenleriyle özetleyip e-postayla gönderir.',
                'instructions' => "Dünkü (Türkiye saatiyle) operasyonu özetle.\n\n1. APM'den dünkü uçuşları al: toplam uçuş, iptaller, kalkışta zamanındalık (rötar = planlanan kalkıştan 15 dk ve üzeri gecikme).\n2. En uzun 5 rötarı sefer, rota, rötar dakikası ve rötar kodlarıyla tabloya koy; kodların anlamını kısaca yaz.\n3. IBS'ten etkilenen rotaların doluluğunu ekleyebilirsen ekle.\n4. Kısa bir sonuç paragrafı ve tabloyla e-posta taslağı hazırla; konu: \"Operasyon özeti · {tarih}\". En fazla 1 e-posta.",
                'tools' => [['name' => 'query_apm'], ['name' => 'describe_apm'], ['name' => 'query_warehouse'], ['name' => 'send_email', 'constraints' => ['max_per_run' => 1]]],
                'trigger' => ['type' => AgentTrigger::SCHEDULE, 'config' => ['cron' => '0 6 * * *'], 'input_template' => 'Dünkü operasyon özetini hazırla ve gönder.'],
                'plan' => [
                    ['type' => 'trigger', 'title' => 'Her gün 06:00', 'detail' => 'Zamanlama · Europe/Istanbul'],
                    ['type' => 'read', 'title' => 'APM · dünkü uçuşlar ve rötar kodları', 'detail' => 'salt okunur'],
                    ['type' => 'lookup', 'title' => 'IBS · etkilenen rotaların doluluğu', 'detail' => 'salt okunur'],
                    ['type' => 'decide', 'title' => 'Rötar 15 dakikadan uzun mu?', 'no' => 'Özete dahil etme'],
                    ['type' => 'draft', 'title' => 'Özet e-postası', 'detail' => 'Auto model'],
                    ['type' => 'approve', 'title' => 'Sahibi onaylar', 'detail' => 'Fortis Inbox'],
                    ['type' => 'act', 'title' => 'E-postayla gönder', 'detail' => 'denetim kaydına yazılır'],
                ],
                'suggested_prompts' => ['Dün en uzun rötar hangi seferdeydi?', 'Bu haftanın zamanında kalkış oranı nasıl?'],
            ],
            'delay-analysis' => [
                'name' => 'Rötar Nedeni Analizi',
                'icon' => 'RN',
                'description' => 'Geçen haftanın rötar kodlarını gruplar, tekrar eden nedenleri ve rotaları açıklar; Excel ekiyle gönderir.',
                'instructions' => "Geçen haftanın (pazartesi–pazar) rötarlarını analiz et.\n\n1. APM'den rötarlı uçuşları al (15 dk ve üzeri).\n2. Rötar kodlarını iş alanına göre grupla; en sık 5 kodu ve toplam dakikayı ver.\n3. Aynı nedenin tekrarlandığı rota ve uçakları belirt.\n4. Ayrıntılı listeyi Excel raporu olarak üret ve kısa bir yorumla e-posta taslağına ekle.",
                'tools' => [['name' => 'query_apm'], ['name' => 'describe_apm'], ['name' => 'create_report'], ['name' => 'send_email', 'constraints' => ['max_per_run' => 1]]],
                'trigger' => ['type' => AgentTrigger::SCHEDULE, 'config' => ['cron' => '0 8 * * 1'], 'input_template' => 'Geçen haftanın rötar nedeni analizini hazırla.'],
                'plan' => [
                    ['type' => 'trigger', 'title' => 'Her pazartesi 08:00'],
                    ['type' => 'read', 'title' => 'APM · geçen haftanın rötarları', 'detail' => 'salt okunur'],
                    ['type' => 'draft', 'title' => 'Kod gruplaması ve Excel raporu'],
                    ['type' => 'approve', 'title' => 'Sahibi onaylar', 'detail' => 'Fortis Inbox'],
                    ['type' => 'act', 'title' => 'E-postayla gönder', 'detail' => 'denetim kaydına yazılır'],
                ],
                'suggested_prompts' => ['Geçen hafta en sık rötar kodu neydi?'],
            ],
            'long-delay-alert' => [
                'name' => 'Uzun Rötar Bildirimi',
                'icon' => 'UR',
                'description' => 'Rötar 2 saati geçince uçağın sonraki bacaklarını ve etkisini özetleyip OCC kanalına bildirir.',
                'instructions' => "Bir uçuşun kalkış rötarı eşiği geçti (olay bilgisi aşağıda).\n\n1. APM'den aynı uçağın bugünkü sonraki bacaklarını bul.\n2. Rötarın sonraki bacaklara olası etkisini (zincirleme rötar) kısaca değerlendir.\n3. Sefer, rota, rötar, rötar kodu ve etkilenen sonraki bacaklarla 5 satırı geçmeyen bir Teams mesajı hazırla.",
                'tools' => [['name' => 'query_apm'], ['name' => 'describe_apm'], ['name' => 'post_teams', 'constraints' => ['max_per_run' => 1]]],
                'trigger' => ['type' => AgentTrigger::EVENT, 'config' => ['event' => 'flight.delayed', 'min_delay_minutes' => 120], 'input_template' => "Uzun rötar oldu, bildirimi hazırla:\n{{event}}"],
                'plan' => [
                    ['type' => 'trigger', 'title' => 'Rötar 120 dakikayı geçince', 'detail' => 'Olay · flight.delayed'],
                    ['type' => 'read', 'title' => 'APM · uçağın sonraki bacakları', 'detail' => 'salt okunur'],
                    ['type' => 'draft', 'title' => 'Kısa bildirim', 'detail' => 'en fazla 5 satır'],
                    ['type' => 'approve', 'title' => 'Sahibi onaylar', 'detail' => 'Fortis Inbox'],
                    ['type' => 'act', 'title' => "Teams'e gönder", 'detail' => 'denetim kaydına yazılır'],
                ],
                'suggested_prompts' => ['Bugün 2 saati geçen rötar var mı?'],
            ],
            'hr-changes' => [
                'name' => 'İK Değişiklik Özeti',
                'icon' => 'İK',
                'description' => 'Haftalık işe giriş, ayrılış ve departman değişikliklerini departmanlara göre özetler.',
                'instructions' => "Bu haftanın (pazartesi–cuma) İK değişikliklerini özetle.\n\n1. SuccessFactors'tan bu hafta işe başlayanları, ayrılanları ve departman değiştirenleri al.\n2. Departmanlara göre sayıları tabloya koy; isimleri sadece işe başlayanlar için yaz.\n3. Kısa bir yorumla e-posta taslağı hazırla.",
                'tools' => [['name' => 'query_successfactors'], ['name' => 'describe_successfactors'], ['name' => 'send_email', 'constraints' => ['max_per_run' => 1]]],
                'trigger' => ['type' => AgentTrigger::SCHEDULE, 'config' => ['cron' => '0 16 * * 5'], 'input_template' => 'Bu haftanın İK değişiklik özetini hazırla.'],
                'plan' => [
                    ['type' => 'trigger', 'title' => 'Her cuma 16:00'],
                    ['type' => 'read', 'title' => 'SuccessFactors · giriş, ayrılış, değişiklik', 'detail' => 'salt okunur'],
                    ['type' => 'draft', 'title' => 'Departman tablosu ve özet'],
                    ['type' => 'approve', 'title' => 'Sahibi onaylar', 'detail' => 'Fortis Inbox'],
                    ['type' => 'act', 'title' => 'E-postayla gönder', 'detail' => 'denetim kaydına yazılır'],
                ],
                'suggested_prompts' => ['Bu ay kaç kişi işe başladı?'],
            ],
        ];
    }
}
