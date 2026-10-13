<?php

return [

    /*
    | Home'daki bağlantı çipleri ve konuşmadaki kaynak seçici. Sıra ekrandaki sıradır.
    | tools: kaynak kapatılınca Claude'a sunulmayan araçlar. Bağlı olmayan sistem "Connected" görünmez.
    */
    'sources' => [
        'ibs' => ['label' => 'IBS', 'abbr' => 'IB', 'tools' => ['query_warehouse', 'describe_warehouse', 'get_queries']],
        'apm' => ['label' => 'APM', 'abbr' => 'AP', 'tools' => ['query_apm', 'describe_apm', 'preview_apm']],
        'successfactors' => ['label' => 'SuccessFactors', 'abbr' => 'SF', 'tools' => ['query_successfactors', 'describe_successfactors', 'preview_successfactors']],
        'outlook' => ['label' => 'Outlook', 'abbr' => 'OL', 'tools' => []],
        'teams' => ['label' => 'Teams', 'abbr' => 'TM', 'tools' => []],
    ],

    // Son başarılı kontrol bundan eskiyse durum "attention" olur (dakika).
    'fresh_minutes' => 15,

];
