<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // Asisten suara: ucapan diurai AI jadi usulan transaksi/perubahan akun (app/Support/VoiceAssistant.php).
    // Gemini dipakai bila GEMINI_API_KEY terisi (kunci dari aistudio.google.com/app/apikey),
    // selain itu Claude. Keduanya kosong = fitur suara mati. Kunci API terpisah dari langganan.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // Dicoba berurutan bila model tidak tersedia / kuotanya habis. Cek daftar di AI Studio.
        'models' => array_values(array_filter(array_map('trim', explode(',',
            env('GEMINI_MODELS') ?: 'gemini-3.6-flash,gemini-3.5-flash,gemini-2.5-flash-lite'
        )))),
        // Suara LAX (text-to-speech). Dicoba berurutan bila tidak tersedia / kuota habis.
        'tts_models' => array_values(array_filter(array_map('trim', explode(',',
            env('GEMINI_TTS_MODELS') ?: 'gemini-2.5-flash-preview-tts,gemini-2.5-pro-preview-tts'
        )))),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL') ?: 'claude-opus-5-5',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
