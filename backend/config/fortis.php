<?php

return [
    /*
    | Giriş yöntemi: password (e-posta + şifre) veya entra (Microsoft Entra ID, OpenID Connect).
    | Kayıt ekranı yok; her iki yöntemde de sadece admin'in eklediği kullanıcılar girebilir.
    */
    'auth_driver' => env('AUTH_DRIVER', 'password'),

    // İlk kurulumda (db:seed) oluşturulan admin. Şifre konsola yazılır, ilk girişte değiştirilmesi istenir.
    'admin_email' => env('FORTIS_ADMIN_EMAIL'),

    // Arayüz dilleri. Seçili dil: config('app.locale').
    'locales' => ['tr', 'en'],

    'login' => [
        // Bu kadar hatalı denemeden sonra e-posta + IP için kilit.
        'max_attempts' => 5,
        'lockout_seconds' => 60,
    ],

    'password_min_length' => 10,
];
