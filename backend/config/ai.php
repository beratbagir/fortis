<?php

return [

    /*
    | Kullanıcının seçebileceği modeller ve fiyatları (USD / 1M token). Fiyatlar Anthropic'in fiyat tablosuyla
    | doğrulandı (2026-10-06). cache_write: 5 dakikalık önbellek yazımı (girdi fiyatının 1,25 katı).
    | Maliyet çağrı anında hesaplanıp ai_calls.cost_usd'ye yazılır; fiyat sonradan değişse de geçmiş değişmez.
    */
    'models' => [
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'input_per_mtok' => 2.00, 'output_per_mtok' => 10.00, 'cache_write_per_mtok' => 2.50, 'cache_read_per_mtok' => 0.20],
        'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'input_per_mtok' => 4.00, 'output_per_mtok' => 20.00, 'cache_write_per_mtok' => 5.00, 'cache_read_per_mtok' => 0.20],
    ],

    'default_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),

    /*
    | Veri sorularında kullanılan model. Claude bir query_* aracı çağırınca aynı cevabın sonraki turları bu modelle
    | devam eder. Kullanıcı sohbette modeli elle seçtiyse onun seçimi geçerlidir.
    */
    'data_question_model' => env('ANTHROPIC_DATA_MODEL', 'claude-opus-5-5'),

    // Kısa işler (sohbet başlığı, sınıflandırma) için hızlı model. Haiku izinli listeye eklenirse sadece burası değişir.
    'fast_model' => env('ANTHROPIC_FAST_MODEL', 'claude-sonnet-5-5'),

    // Modellerin model seçicide görünen güçlü yönü.
    'model_strengths' => [
        'claude-opus-5-5' => 'Çok adımlı derin analiz',
        'claude-sonnet-5-5' => 'Dengeli, çoğu iş için varsayılan',
        'claude-haiku-5-5' => 'Hızlı ve düşük maliyetli sınıflandırma',
    ],

    // Model seçicinin altındaki politika notu. {data} veri erişimi ayarına göre doldurulur. Doğrulanmamış ifade yazılmaz.
    'policy_note' => 'İzinli modeller yöneticin tarafından belirlenir · {data}',

    /*
    | Seçilemeyen ama maliyet hesabı için gereken modeller: sunucu tarafı yedek model geçişleri ve eski kayıtlar.
    */
    'pricing_only' => [
        'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'input_per_mtok' => 2.00, 'output_per_mtok' => 10.00, 'cache_write_per_mtok' => 2.50, 'cache_read_per_mtok' => 0.20],
        'claude-opus-5' => ['label' => 'Claude Opus 5', 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'cache_write_per_mtok' => 6.25, 'cache_read_per_mtok' => 0.50],
        'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input_per_mtok' => 1.00, 'output_per_mtok' => 5.00, 'cache_write_per_mtok' => 1.25, 'cache_read_per_mtok' => 0.10],
    ],

    /*
    | Bütçe uyarısı (%80) ve bağlantı onayı. Rol sistemi gelene kadar yönetici listesi .env'den okunur.
    */
    'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('FORTIS_ADMIN_EMAILS', ''))))),

    'budget_alert_ratio' => 0.8,

];
