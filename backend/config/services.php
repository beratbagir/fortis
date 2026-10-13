<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // low | medium | high | xhigh | max. Sohbet ve araç kullanan akış üretimi için medium.
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 32000),
        // Sunucu tarafı yedek model: bir istek güvenlik sınıflandırıcısına takılırsa API aynı isteği
        // uygun bir modelde yeniden çalıştırır (beta, sadece Claude API).
        'fallbacks' => (bool) env('ANTHROPIC_FALLBACKS', true),
        // Model listesi ve fiyatlar: config/ai.php
    ],

    // Microsoft Entra ID ile giriş (AUTH_DRIVER=entra). Uygulama kaydını IT açar; redirect: <APP_URL>/api/auth/entra/callback
    'microsoft' => [
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'redirect' => env('ENTRA_REDIRECT_URI', '/api/auth/entra/callback'),
        'tenant' => env('ENTRA_TENANT_ID', 'common'),
    ],

];
