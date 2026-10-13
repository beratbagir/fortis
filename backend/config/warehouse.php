<?php

return [

    /*
    | IBS veri ambarı. Laravel bu bağlantıyı sadece okumak için kullanır: tablo bilgisi (describe_warehouse),
    | SQL kontrolü (EXPLAIN). Akışlardaki sorgular Kestra'nın MySQL göreviyle çalışır.
    */

    'connection' => 'ibs',

    'enabled' => filled(env('IBS_DB_HOST')),

    'queries_path' => resource_path('warehouse/queries.json'),

    'dictionary_path' => resource_path('warehouse/tables.json'),

    // Akış SQL'lerinde zorunlu sınırlar.
    'max_execution_ms' => 120000,

    'max_limit' => 5000,

    /*
    | Claude'un query_warehouse aracında kişi düzeyinde satır döndüremeyeceği tablolar (adında geçen parça).
    | Bu tablolar sadece sayım sorgularında (COUNT/SUM... ve kişi kimliğiyle gruplanmayan GROUP BY) kullanılabilir.
    */
    'ai_blocked_tables' => [
        'pnr_pax', 'chk_passenger', 'chk_pax_', 'inf_details', 'pnr_ssr', 'pnr_seat', 'ticket_details', 'ticket_coupon',
        'mco_coupon', 'user_details', 'agency_address', 'grp_quote_pax', 'nl_subscription',
    ],

    // Tablo listesinin önbellek süresi (saniye).
    'cache_seconds' => 86400,

];
