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

    /*
    | The AI Trade Assistant. "anthropic" is the only provider so far; leave
    | AI_PROVIDER blank to switch the assistant off. The key is only used on
    | the server and is never shown on a page or written to the logs.
    */
    'ai' => [
        'provider' => env('AI_PROVIDER'),
        'key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'max_retries' => (int) env('AI_MAX_RETRIES', 1),
    ],

    /*
    | Automatic stock prices for the "Refresh Stock Prices" button. "tiingo"
    | is the only provider so far; leave MARKET_DATA_PROVIDER blank to switch
    | it off. The key is only used on the server and is never shown on a page
    | or written to the logs.
    */
    'market_data' => [
        'provider' => env('MARKET_DATA_PROVIDER'),
        'key' => env('MARKET_DATA_API_KEY'),
        'timeout' => (int) env('MARKET_DATA_TIMEOUT', 10),
    ],

];
