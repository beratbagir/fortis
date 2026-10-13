<?php

return [

    /*
    | SAP SuccessFactors (İK sistemi) OData v2. Laravel sadece şemayı ($metadata) okur; akışlar Kestra'nın HTTP
    | göreviyle ve SF_USERNAME / SF_PASSWORD secret'larıyla istek atar.
    */

    'base_url' => rtrim((string) env('SF_BASE_URL', ''), '/').'/',

    'username' => env('SF_USERNAME'),

    'password' => env('SF_PASSWORD'),

    'enabled' => filled(env('SF_BASE_URL')),

    // false: akışlar SuccessFactors'a sadece GET atabilir (upsert gibi yazma işlemleri kapalı).
    'allow_writes' => (bool) env('SF_ALLOW_WRITES', false),

    'requests_path' => resource_path('successfactors/requests.json'),

    'cache_seconds' => 86400,

    'timeout' => 30,

];
