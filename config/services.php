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

    /*
    | WhatsApp Cloud API. The templates themselves live in `message_templates`;
    | these are the credentials the sender posts them with.
    |
    | Nothing here belongs in git — the token is a permanent system-user token
    | and grants the right to message every patient the clinic has.
    */
    'whatsapp' => [
        'token' => env('WHATSAPP_SYSTEM_USER_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v25.0'),
        // Meta's URL buttons are configured as `https://<domain>/{{1}}`, so the
        // suffix we send is a whole path. This is that domain.
        'link_base' => env('WHATSAPP_LINK_BASE', env('APP_URL')),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
