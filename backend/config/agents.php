<?php

return [

    /*
    | Agentların kullanabileceği araçlar. kind: read (A1'deki okuma araçları) | write (dışarıya yazan; her zaman
    | agent_actions üzerinden, onay politikasına göre). Agent'a sadece kendi `tools` listesindekiler verilir.
    */
    'tools' => [
        'query_apm' => ['kind' => 'read', 'system' => 'APM', 'label' => 'APM sorgusu', 'description' => 'Uçuşlar, rötarlar, uçaklar, zamanındalık.'],
        'query_warehouse' => ['kind' => 'read', 'system' => 'IBS', 'label' => 'IBS sorgusu', 'description' => 'Rezervasyon, satış, sefer, doluluk.'],
        'query_successfactors' => ['kind' => 'read', 'system' => 'SuccessFactors', 'label' => 'SuccessFactors sorgusu', 'description' => 'Çalışan, departman, pozisyon.'],
        'describe_warehouse' => ['kind' => 'read', 'system' => 'IBS', 'label' => 'IBS tablo bilgisi', 'description' => 'Tablo ve sütunlar.'],
        'get_queries' => ['kind' => 'read', 'system' => 'IBS', 'label' => 'IBS hazır sorgular', 'description' => 'Onaylı hazır sorgular.'],
        'describe_apm' => ['kind' => 'read', 'system' => 'APM', 'label' => 'APM uç bilgisi', 'description' => 'APM işlemleri ve alanları.'],
        'describe_successfactors' => ['kind' => 'read', 'system' => 'SuccessFactors', 'label' => 'SuccessFactors alan bilgisi', 'description' => 'Varlıklar ve alanlar.'],
        'list_report_sources' => ['kind' => 'read', 'system' => 'Fortis', 'label' => 'Rapor kaynakları', 'description' => 'Rapor üretilebilecek kaynaklar.'],
        'create_report' => ['kind' => 'read', 'system' => 'Fortis', 'label' => 'Rapor dosyası üret', 'description' => 'Excel, CSV veya PDF (dosya üretmek okuma sayılır).'],
        'send_email' => ['kind' => 'write', 'system' => 'E-posta', 'label' => 'E-posta gönder', 'description' => 'İzinli alıcılara e-posta.'],
        'post_teams' => ['kind' => 'write', 'system' => 'Teams', 'label' => 'Teams mesajı', 'description' => 'İzinli Teams kanalına mesaj.'],
        'deliver_report' => ['kind' => 'write', 'system' => 'E-posta', 'label' => 'Raporu gönder', 'description' => 'Üretilen rapor dosyasını e-postayla veya Teams bağlantısıyla gönderir.'],
    ],

    // Şirket içi sayılan e-posta alan adları. Bunların dışı "şirket dışı"dır: her zaman onay ister.
    'allowed_recipient_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_ALLOWED_RECIPIENT_DOMAINS', 'freebird.com.tr,gozendigital.com'))))),

    // Admin politikası: şirket dışı alıcıya gönderim tamamen kapatılabilir.
    'allow_external_recipients' => (bool) env('AGENT_ALLOW_EXTERNAL_RECIPIENTS', false),

    // İzinli Teams kanalları: ad => gelen webhook adresi (.env).
    'teams_channels' => array_filter([
        'occ-operasyon' => env('TEAMS_WEBHOOK_OCC'),
    ]),

    // Agent e-postalarının gönderen adresi.
    'mail_from' => ['address' => env('AGENT_MAIL_FROM', env('MAIL_FROM_ADDRESS', 'fortis@gozendigital.com')), 'name' => env('AGENT_MAIL_FROM_NAME', 'Fortis')],

    // Bir çalışmanın sınırları.
    'max_tool_calls' => (int) env('AGENT_MAX_TOOL_CALLS', 20),
    'max_cost_usd' => (float) env('AGENT_MAX_COST_USD', 2.00),
    'max_seconds' => 600,

    // Varsayılan onay politikası (6.2): her yazan araç her zaman onaylı. Onaysız modlar sadece builder/admin'in
    // Yapılandırma'dan elle seçimiyle (never: sadece admin). Architect onay modu belirleyemez. Pilotta her zaman onay.
    'default_approval_policy' => [
        'send_email' => ['mode' => 'always'],
        'post_teams' => ['mode' => 'always'],
        'deliver_report' => ['mode' => 'always'],
        'approvers' => ['owner'],
    ],

    'queue' => 'agents',

    /*
    | Architect (A3.3): plan adım türleri (açıklayıcı; agent çalışırken talimatın parçası olarak görülür) ve
    | Oluştur ekranındaki örnekler. requires: gereken bağlantılar (config/connectors.php); yapılandırılmamışsa örnek gösterilmez.
    */
    'plan_step_types' => [
        'trigger' => 'TETİKLEYİCİ',
        'read' => 'OKU',
        'lookup' => 'ARA',
        'decide' => 'KARAR',
        'draft' => 'TASLAK',
        'approve' => 'ONAY',
        'act' => 'GÖNDER',
    ],
    'examples' => [
        ['text' => 'Her sabah 06:00\'da dünkü rötarları ve nedenlerini özetleyip OCC ekibine e-postayla gönder', 'requires' => ['apm']],
        ['text' => 'Bir uçuşun rötarı 2 saati geçince uçağın sonraki bacaklarını özetleyip ops ekibine Teams\'ten bildir', 'requires' => ['apm']],
        ['text' => 'Her pazartesi geçen haftanın satışlarını kanallara göre karşılaştırıp en büyük üç değişikliği açıkla', 'requires' => ['ibs']],
        ['text' => 'Her cuma bu haftanın işe giriş ve ayrılışlarını departmanlara göre özetle', 'requires' => ['successfactors']],
    ],

    /*
    | Zamanlama tetikleyicileri (A3.2): Laravel her tetikleyici için Kestra'ya tek görevli bir akış yükler; akış
    | zamanı gelince iç uca (POST /api/internal/agents/{id}/runs) X-Fortis-Key ile istek atar. Anahtarın değeri
    | Laravel'de .env'de, Kestra'da secret olarak durur (secret adı KESTRA_SECRETS listesinde olmalı).
    */
    'kestra_namespace' => 'fortis.agents',
    'internal_key' => env('FORTIS_INTERNAL_KEY'),
    'internal_key_secret' => 'FORTIS_INTERNAL_KEY',
    // Kestra'nın Laravel'e ulaştığı adres (yerelde tünel adresi olabilir).
    'internal_url' => rtrim((string) (env('FORTIS_INTERNAL_URL') ?: env('APP_URL', 'http://localhost')), '/'),
    'timezone' => 'Europe/Istanbul',

    /*
    | Olay tetikleyicileri: APM uçuş bacakları periyodik okunur, değişiklikten olay üretilir.
    | flight.delayed: kalkış rötarı tetikleyicinin eşiğini (min_delay_minutes) ilk kez geçince.
    | flight.cancelled: sefer iptal olunca. İlk kez görülen uçuş sadece kaydedilir, olay üretmez.
    */
    'events' => [
        'flight.delayed' => ['label' => 'Uçuş rötarı', 'default_min_delay_minutes' => 60],
        'flight.cancelled' => ['label' => 'Uçuş iptali'],
    ],
    'flight_watch_hours_back' => 6,
    'flight_watch_hours_ahead' => 18,
    // Bir olay tetikleyicisi saatte en fazla bu kadar çalışma başlatır (olay fırtınasına karşı).
    'max_event_runs_per_hour' => (int) env('AGENT_MAX_EVENT_RUNS_PER_HOUR', 10),

];
