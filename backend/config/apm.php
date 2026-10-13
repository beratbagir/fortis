<?php

return [

    /*
    | APM REST API (uçuş operasyonları: bacaklar, uçuş logları, gecikmeler, ekip, OTP raporları).
    | Bilgi kaynağı repodaki OpenAPI dokümanıdır. APM iç ağdadır; Kestra'ya tünel üzerinden açılan adres
    | (kestra_base_url) akışlarda kullanılır ve doğrulayıcı bu adrese iç ağ istisnası tanır.
    */

    'spec_path' => dirname(base_path()).'/docs/integrations/apm-openapi.json',

    // Akışlardaki adres (Kestra konteynerinin gördüğü, tünel üzerinden).
    'kestra_base_url' => rtrim((string) env('APM_KESTRA_BASE_URL', ''), '/'),

    // Laravel'in (bu makine, VPN) doğrudan eriştiği adres: Claude'un istekleri denemesi (preview_apm) için.
    'base_url' => rtrim((string) env('APM_BASE_URL', ''), '/'),

    'client_id' => env('APM_CLIENT_ID'),
    'client_secret' => env('APM_CLIENT_SECRET'),
    'username' => env('APM_USERNAME'),
    'password' => env('APM_PASSWORD'),

    // client_credentials (APM_CLIENT_ID, APM_CLIENT_SECRET) veya password (APM_USERNAME, APM_PASSWORD).
    'grant_type' => env('APM_GRANT_TYPE', 'client_credentials'),

    'allow_writes' => (bool) env('APM_ALLOW_WRITES', false),

    // Yazma kapalıyken de izinli POST'lar: token alma ve veri döndüren rapor uçları.
    'read_only_posts' => [
        '/oauth/token',
        '/api/reports/legs/legsOnTimeAtDepartureReport',
        '/api/reports/legs/legsOnTimeAtArrivalReport',
    ],

];
