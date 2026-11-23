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

    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'meta'),
        'meta' => [
            'api_base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
            'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
            'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
            'app_secret' => env('WHATSAPP_APP_SECRET'),
            // Approved Meta template with an IMAGE header and two body parameters
            // ({{1}} guest name, {{2}} event name). Without it, invitations are sent as
            // free-form media, which Meta only accepts inside a 24-hour customer window.
            'invitation_template' => env('WHATSAPP_INVITATION_TEMPLATE'),
            'invitation_template_language' => env('WHATSAPP_INVITATION_TEMPLATE_LANGUAGE', 'sw'),
        ],
    ],

    'offline_scanner_package' => [
        'protocol_version' => env('OFFLINE_SCANNER_PROTOCOL_VERSION', '1.0'),
        'minimum_android_app_version' => env('OFFLINE_SCANNER_MIN_ANDROID_APP_VERSION', '1.0.0'),
        'pbkdf2_iterations' => (int) env('OFFLINE_SCANNER_PACKAGE_PBKDF2_ITERATIONS', 210000),
        'signature_algorithm' => env('OFFLINE_SCANNER_PACKAGE_SIGNATURE_ALGORITHM', 'ed25519'),
        'signing_key_id' => env('OFFLINE_SCANNER_PACKAGE_SIGNING_KEY_ID', 'server-key-1'),
        'ed25519_private_key_base64' => env('OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64'),
        'allow_hmac_fallback' => (bool) env('OFFLINE_SCANNER_PACKAGE_ALLOW_HMAC_FALLBACK', false),
        'hmac_key' => env('OFFLINE_SCANNER_PACKAGE_HMAC_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
