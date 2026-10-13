<?php

return [

    /*
    | Sesli komut. Ses şirket içindeki Whisper servisinde metne çevrilir (infra/whisper); dış bir ses tanıma
    | API'si kullanılmaz. Ses saklanmaz: geçici dosya çeviriden sonra her durumda silinir. Sadece ölçüm
    | (süre, işlem zamanı, karakter sayısı) voice_transcriptions tablosuna yazılır; metin yazılmaz.
    */

    // false iken mikrofon butonu arayüzde görünmez ve uç 404 döner.
    'enabled' => (bool) env('VOICE_ENABLED', true),

    // Sadece Laravel erişir. Geliştirmede servis bu Mac'te: 127.0.0.1:9000.
    'whisper_url' => rtrim((string) env('WHISPER_URL', 'http://127.0.0.1:9000'), '/'),

    'timeout' => (int) env('WHISPER_TIMEOUT', 30),

    'max_seconds' => 30,

    'max_kilobytes' => 5 * 1024,

    // Kullanıcı (şimdilik IP) başına dakikada en fazla istek.
    'per_minute' => (int) env('VOICE_PER_MINUTE', 20),

    'tmp_dir' => storage_path('app/tmp/voice'),

    // Dosya içeriğinden algılanan türler. MediaRecorder'ın webm/mp4 çıktısı video/* olarak algılanır;
    // wav için audio/x-wav da gelir.
    'mime_types' => [
        'audio/webm', 'video/webm',
        'audio/ogg',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
        'audio/mp4', 'audio/x-m4a', 'video/mp4',
    ],

    // Whisper'a her istekte initial_prompt olarak gider (tanıma ipucu).
    // infra/whisper/tests/test_accuracy.py içindeki kopyayla aynı tutulmalı.
    // Havalimanı listesi ileride APM verisinden doldurulabilir; şimdilik sabit.
    'vocabulary' => 'Fortis, akış, blok, tetikleyici, rötar, iptal, STD, ETD, OCC, '
        .'APM, IBS, SuccessFactors, Teams, Outlook, Kestra, '
        .'AYT, IST, SAW, ESB, ADB, DLM, BJV, DUS, FRA, MUC, CGN, '
        .'e-posta, Excel, PDF, rapor, dakika, saat',

    // Whisper'ın sessizlikte ürettiği bilinen anlamsız çıktılar. Metnin tamamı (küçük harf, noktalama
    // ve boşluklar yok sayılarak) bunlardan biriyse metin boş sayılır.
    'hallucination_filters' => [
        'Altyazı M.K.',
        'Altyazı',
        'Alt yazı M.K.',
        'İzlediğiniz için teşekkürler',
        'İzlediğiniz için teşekkür ederim',
        'Bizi izlediğiniz için teşekkürler',
        'Abone olmayı unutmayın',
        'Videoyu beğenmeyi ve abone olmayı unutmayın',
        'Bir sonraki videoda görüşmek üzere',
        'Teşekkürler',
        'Müzik',
        '[Müzik]',
        'Sesli betimleme',
    ],

];
