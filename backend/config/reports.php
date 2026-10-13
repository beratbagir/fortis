<?php

return [

    /*
    | Excel / CSV / PDF raporları. Dosyayı AI değil Laravel üretir; veriyi de Laravel kendisi çeker.
    | Claude'a sadece satır sayısı ve katalogda toplanabilir işaretli alanların sayımları gider.
    */

    'sources_path' => resource_path('reports/sources.json'),

    // Bir raporun en fazla satırı. Aşılırsa rapor üretilmez, kullanıcıdan filtreyi daraltması istenir.
    'max_rows' => (int) env('REPORT_MAX_ROWS', 50000),

    // Bu kadar satıra kadar rapor hemen üretilir; fazlası kuyruğa gider.
    'sync_rows' => (int) env('REPORT_SYNC_ROWS', 5000),

    // Dosyalar bu kadar gün saklanır; sonra reports:prune siler.
    'retention_days' => (int) env('REPORT_RETENTION_DAYS', 7),

    // İmzalı indirme bağlantısının geçerlilik süresi (dakika).
    'link_minutes' => 15,

    // Özel disk (public değil); yol: reports/{yyyy}/{mm}/{id}.{ext}
    'disk' => 'local',

    'formats' => ['xlsx', 'csv', 'pdf'],

    'timezone' => 'Europe/Istanbul',

];
