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

    'muthobarta' => [
        'api_key' => env('MUTHOBARTA_API_KEY'),
        'base_url' => env('MUTHOBARTA_BASE_URL'),
        'sender_id' => env('MUTHOBARTA_SENDER_ID', 'Dropship'),
        'admin_number' => env('MUTHOBARTA_ADMIN_NUMBER', '01941606310'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'enabled' => filter_var(env('WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v22.0'),
        'activation_template' => trim((string) env('WHATSAPP_ACTIVATION_TEMPLATE', 'dropshipper_activation')),
        'template_language' => trim((string) env('WHATSAPP_TEMPLATE_LANGUAGE', 'bn')),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'login_url' => env('RESELLERBRAIN_LOGIN_URL', 'https://resellerbrain.com/login'),
    ],

];
