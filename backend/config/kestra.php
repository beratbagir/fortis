<?php

return [

    /*
    | Kestra sunucusu. React ve Claude Kestra'ya asla doğrudan erişmez;
    | tüm iletişim App\Services\Kestra\KestraClient üzerinden geçer.
    */

    'url' => rtrim((string) env('KESTRA_URL', 'http://127.0.0.1:8080'), '/'),

    'username' => env('KESTRA_USERNAME'),

    'password' => env('KESTRA_PASSWORD'),

    // OSS sürümde tek tenant vardır: "main".
    'tenant' => env('KESTRA_TENANT', 'main'),

    // Sunucuda çalışan sabit sürüm. Bazı uç noktaların yolu sürüme göre değişir
    // (ör. 2.0'da resume/kill uçları /actions/ altına taşındı).
    'version' => env('KESTRA_VERSION', '2.0.4'),

    // Chat'ten üretilen akışların namespace'i.
    'namespace' => env('KESTRA_NAMESPACE', 'fortis.chat'),

    // Editördeki "Test çalıştır": akışın kuru çalıştırma kopyası bu namespace'e yüklenir ve bir kez çalıştırılır.
    'test_namespace' => env('KESTRA_TEST_NAMESPACE', 'fortis.chat.test'),

    'timeout' => (int) env('KESTRA_TIMEOUT', 15),

    // Kestra'da tanımlı secret'ların sadece İSİMLERİ (virgülle ayrılmış). Değerler Kestra'da durur;
    // Laravel ve Claude değerleri hiçbir zaman görmez. Örnek: SMTP_HOST,SMTP_PASSWORD
    'secrets' => array_values(array_filter(array_map('trim', explode(',', (string) env('KESTRA_SECRETS', ''))))),

    // Akışların HTTP isteği atamayacağı ek sunucu adları (virgülle ayrılmış). Kestra'nın ve uygulamanın
    // kendi sunucu adları her zaman engellidir.
    'blocked_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('KESTRA_BLOCKED_HOSTS', ''))))),

    // İzinli görev tipleri, açıklamaları ve örnekleri.
    'catalog_path' => config_path('kestra-catalog.json'),

];
