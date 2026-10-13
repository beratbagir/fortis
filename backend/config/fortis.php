<?php

return [
    /*
    | Giriş yöntemi: password (e-posta + şifre) veya entra (Microsoft Entra ID, OpenID Connect).
    | Kayıt ekranı yok; her iki yöntemde de sadece admin'in eklediği kullanıcılar girebilir.
    */
    'auth_driver' => env('AUTH_DRIVER', 'password'),

    // İlk kurulumda (db:seed) oluşturulan admin. Şifre konsola yazılır, ilk girişte değiştirilmesi istenir.
    'admin_email' => env('FORTIS_ADMIN_EMAIL'),

    // Arayüz dilleri. Varsayılan config('app.locale') (en); her kullanıcı kendi dilini seçer (users.locale).
    'locales' => ['en', 'tr'],

    'login' => [
        // Bu kadar hatalı denemeden sonra e-posta + IP için kilit.
        'max_attempts' => 5,
        'lockout_seconds' => 60,
    ],

    'password_min_length' => 10,

    // Henüz yapılmamış ekranlar (Yapılacaklar, Çıktılar, Bellek, Beceriler…) menüde "Yakında" olarak görünsün mü.
    // Pilotta kapalı; tasarım gösterimi için açılabilir. Adresle açılınca boş durum sayfası her zaman çalışır.
    'show_coming_soon' => (bool) env('FORTIS_SHOW_COMING_SOON', false),
];
