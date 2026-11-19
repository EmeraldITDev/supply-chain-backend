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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // gemini-2.0-flash is shut down; use 2.5+ (or gemini-flash-latest).
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'https://scm.emeraldcfze.com'),

    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI', 'https://supply-chain-backend-hwh6.onrender.com/api/quickbooks/callback'),
        'environment' => env('QUICKBOOKS_ENVIRONMENT', 'production'),
        'base_url' => env('QUICKBOOKS_BASE_URL'),
        'auth_url' => env('QUICKBOOKS_AUTH_URL'),
        'token_url' => env('QUICKBOOKS_TOKEN_URL'),
        'scopes' => env('QUICKBOOKS_SCOPES', 'com.intuit.quickbooks.accounting'),
        'encryption_key' => env('QUICKBOOKS_ENCRYPTION_KEY'),
        'dry_run' => env('QUICKBOOKS_DRY_RUN') === null
            ? true
            : filter_var(env('QUICKBOOKS_DRY_RUN'), FILTER_VALIDATE_BOOLEAN),
        'writes_enabled' => env('QUICKBOOKS_WRITES_ENABLED') === null
            ? false
            : filter_var(env('QUICKBOOKS_WRITES_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'default_item_id' => env('QUICKBOOKS_DEFAULT_ITEM_ID'),
        'default_expense_account_id' => env('QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID'),
        'ap_account_id' => env('QUICKBOOKS_AP_ACCOUNT_ID'),
        'minor_version' => (int) env('QUICKBOOKS_MINOR_VERSION', 75),
        'doc_number_max_length' => 21,
    ],

];
