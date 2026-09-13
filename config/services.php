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

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    'ipwhois' => [
        'base_url' => env('PORTAL_IPWHOIS_URL', 'https://ipwho.is'),
        'lookup_private' => filter_var(
            env('PORTAL_IP_LOOKUP_PRIVATE', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    'ipapi' => [
        'base_url' => env('PORTAL_IP_GEOLOCATION_URL', 'https://ipapi.co'),
    ],

    'portal_azure' => [
        'tenant_id' => env('PORTAL_AZURE_TENANT_ID'),
        'client_id' => env('PORTAL_AZURE_CLIENT_ID'),
        'client_secret' => env('PORTAL_AZURE_CLIENT_SECRET'),
        'redirect_uri' => env('PORTAL_AZURE_REDIRECT_URI', 'http://localhost:8081'),
    ],

];
