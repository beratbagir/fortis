<?php

return [

    /*
    | Fortis'in bağlantıları. Sıra ekrandaki sıradır.
    | - category: data (veri kaynağı) | channel (teslim kanalı) | setup (henüz entegrasyonu yok, "Kurulum gerekli").
    | - chip: Home'daki bağlantı çiplerinde ve konuşmadaki kaynak seçicide görünür.
    | - tools: kaynak (sohbette veya şirket genelinde) kapatılınca Claude'a sunulmayan araçlar. Agent araç kataloğundaki
    |   (config/agents.php) aynı sistemin araçları da eklenir; kapsam etiketleri (read / write·approval / events) oradan türetilir.
    | - system: config/agents.php araç kataloğundaki sistem adı.
    | - env: .env değişken ADI => okunduğu config anahtarı. Ekranda sadece ad ve tanımlı mı görünür; değer hiçbir yanıtta
    |   yer almaz. Config üzerinden bakılır (config önbelleğindeyken env() boş döner).
    | - needs: kurulum için gerekenler (IT'ye iletilecek metin).
    | Bağlı olmayan sistem "Connected" görünmez. Kimlik bilgisi tutulmaz.
    */
    'sources' => [
        'ibs' => [
            'label' => 'IBS', 'abbr' => 'IB', 'category' => 'data', 'chip' => true, 'system' => 'IBS',
            'description' => 'Rezervasyon, satış, sefer ve kapasite verisi (veri ambarı, salt okunur).',
            'tools' => ['query_warehouse', 'describe_warehouse', 'get_queries'],
            'env' => ['IBS_DB_HOST' => 'database.connections.ibs.host', 'IBS_DB_PORT' => 'database.connections.ibs.port', 'IBS_DB_DATABASE' => 'database.connections.ibs.database', 'IBS_DB_USERNAME' => 'database.connections.ibs.username', 'IBS_DB_PASSWORD' => 'database.connections.ibs.password'],
        ],
        'apm' => [
            'label' => 'APM', 'abbr' => 'AP', 'category' => 'data', 'chip' => true, 'system' => 'APM',
            'description' => 'Uçuşlar, rötarlar, uçaklar ve zamanındalık (REST API, salt okunur).',
            'tools' => ['query_apm', 'describe_apm', 'preview_apm'],
            'env' => ['APM_BASE_URL' => 'apm.base_url', 'APM_KESTRA_BASE_URL' => 'apm.kestra_base_url', 'APM_GRANT_TYPE' => 'apm.grant_type', 'APM_CLIENT_ID' => 'apm.client_id', 'APM_CLIENT_SECRET' => 'apm.client_secret', 'APM_USERNAME' => 'apm.username', 'APM_PASSWORD' => 'apm.password'],
        ],
        'successfactors' => [
            'label' => 'SuccessFactors', 'abbr' => 'SF', 'category' => 'data', 'chip' => true, 'system' => 'SuccessFactors',
            'description' => 'Çalışan, departman, pozisyon ve lokasyon (OData, salt okunur).',
            'tools' => ['query_successfactors', 'describe_successfactors', 'preview_successfactors'],
            'env' => ['SF_BASE_URL' => 'successfactors.enabled', 'SF_USERNAME' => 'successfactors.username', 'SF_PASSWORD' => 'successfactors.password'],
        ],
        'outlook' => [
            'label' => 'Outlook', 'abbr' => 'OL', 'category' => 'setup', 'chip' => true,
            'description' => 'Kişisel posta kutusundan okuma ve gönderme (Microsoft Graph).',
            'tools' => [],
            'needs' => ['Entra ID uygulama kaydı (Fortis)', 'Microsoft Graph izinleri: Mail.Read, Mail.Send (yönetici onaylı)', 'Hangi posta kutularının bağlanacağı (paylaşılan kutular dahil)'],
        ],
        'email' => [
            'label' => 'E-posta', 'abbr' => 'EP', 'category' => 'channel', 'chip' => false, 'system' => 'E-posta',
            'description' => 'Agent e-postaları ve rapor teslimi (onaydan sonra).',
            'tools' => [],
            'env' => ['MAIL_MAILER' => 'mail.default', 'MAIL_HOST' => 'mail.mailers.smtp.host', 'MAIL_PORT' => 'mail.mailers.smtp.port', 'MAIL_USERNAME' => 'mail.mailers.smtp.username', 'MAIL_PASSWORD' => 'mail.mailers.smtp.password', 'MAIL_FROM_ADDRESS' => 'mail.from.address', 'AGENT_MAIL_FROM' => 'agents.mail_from.address'],
        ],
        'teams' => [
            'label' => 'Teams', 'abbr' => 'TM', 'category' => 'channel', 'chip' => true, 'system' => 'Teams',
            'description' => 'İzinli kanallara mesaj (gelen webhook, onaydan sonra).',
            'tools' => [],
            'env' => ['TEAMS_WEBHOOK_OCC' => 'agents.teams_channels.occ-operasyon'],
        ],
        'sharepoint' => [
            'label' => 'SharePoint', 'abbr' => 'SP', 'category' => 'setup', 'chip' => false,
            'description' => 'Sigorta, sözleşme ve finans dokümanları.',
            'tools' => [],
            'needs' => ['Entra ID uygulama kaydı (Fortis)', 'Microsoft Graph izni: Sites.Selected', 'Okunacak site ve kütüphanelerin listesi'],
        ],
        'airrm' => [
            'label' => 'AirRM', 'abbr' => 'RM', 'category' => 'setup', 'chip' => false,
            'description' => 'Gelir yönetimi tahminleri ve fiyat sınıfları.',
            'tools' => [],
            'needs' => ['AirRM API erişimi (salt okunur servis hesabı)', 'API dokümantasyonu ve test ortamı adresi', 'Yazma (fiyat aksiyonu) isteniyorsa ayrıca onay akışı kararı'],
        ],
        'oracle' => [
            'label' => 'Oracle', 'abbr' => 'OR', 'category' => 'setup', 'chip' => false,
            'description' => 'Finans, bütçe ve tedarikçi verisi.',
            'tools' => [],
            'needs' => ['Salt okunur veritabanı kullanıcısı veya rapor görünümleri', 'Ağ erişimi (VPN / IP izni)', 'Kişisel veri içeren tabloların listesi (maskeleme için)'],
        ],
        'skybreathe' => [
            'label' => 'SkyBreathe', 'abbr' => 'SB', 'category' => 'setup', 'chip' => false,
            'description' => 'Yakıt verimliliği analizleri.',
            'tools' => [],
            'needs' => ['SkyBreathe API anahtarı (salt okunur)', 'Hangi raporların/göstergelerin kullanılacağı'],
        ],
        'octopus' => [
            'label' => 'Octopus', 'abbr' => 'OC', 'category' => 'setup', 'chip' => false,
            'description' => 'Operasyon olayları ve olay raporları.',
            'tools' => [],
            'needs' => ['Octopus API veya veritabanı erişimi (salt okunur)', 'Olay bildirimi için webhook imkânı (olay tetikleyicisi)'],
        ],
    ],

    // Son başarılı kontrol bundan eskiyse durum "attention" olur (dakika).
    'fresh_minutes' => 15,

    // Kataloğu agent araçlarında olmayan sohbet araçlarının açıklaması (bağlantı detayındaki araç listesi için).
    'tool_descriptions' => [
        'preview_apm' => 'APM isteğini akış kurmadan önce denemek (örnek kayıtlar maskeli).',
        'preview_successfactors' => 'SuccessFactors isteğini akış kurmadan önce denemek (örnek kayıtlar maskeli).',
    ],

    // "Şimdi kontrol et" aynı bağlantı için en fazla bu sıklıkta (saniye).
    'manual_check_seconds' => 60,

];
