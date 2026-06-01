<?php

return [
    // Phase 7: MRFs with created_at >= this date use Finance AP; earlier MRFs use internal SCM finance.
    // No feature flag — set once at go-live (FINANCE_AP_CUTOVER_DATE in .env).
    'cutover_date' => env('FINANCE_AP_CUTOVER_DATE'),

    'base_url' => rtrim((string) env('FINANCE_AP_BASE_URL', 'https://financeap-backend.onrender.com'), '/'),

    'webhook_secret' => env('FINANCE_AP_WEBHOOK_SECRET'),

    'api_key' => env('FINANCE_AP_API_KEY'),

    'integration_inbound_key' => env('FINANCE_AP_INTEGRATION_INBOUND_KEY', env('FINANCE_AP_API_KEY')),

    'enabled' => filter_var(env('FINANCE_AP_INTEGRATION_ENABLED', true), FILTER_VALIDATE_BOOL),

    'http_timeout' => (int) env('FINANCE_AP_HTTP_TIMEOUT', 30),

    'paths' => [
        'package' => '/api/v1/integrations/scm/packages',
        'delta' => '/api/v1/integrations/scm/packages/{scm_transaction_id}/delta',
        'document_refresh' => '/api/v1/integrations/scm/documents/{scm_transaction_id}/{document_id}',
    ],

    'webhook' => [
        'signature_header' => 'X-Finance-Ap-Signature',
        'event_id_header' => 'X-Finance-Ap-Event-Id',
    ],
];
