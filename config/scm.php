<?php

return [
    'po_cc_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'PO_CC_RECIPIENTS',
        'lateef.olanrewaju@emeraldcfze.com,bunmi.babajide@emeraldcfze.com'
    ))))),
    'po_generated_to_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'PO_GENERATED_TO_RECIPIENTS',
        'viva.musa@emeraldcfze.com'
    ))))),
    'invoice_submission_email' => env('PO_INVOICE_SUBMISSION_EMAIL', 'accountpayables@emeraldcfze.com'),
    'invoice_submission_cc' => env('PO_INVOICE_SUBMISSION_CC', 'lateef.olanrewaju@emeraldcfze.com'),
    // Always copied on fleet / logistics system emails (comma-separated). Default: logistics manager.
    'logistics_notification_cc_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'LOGISTICS_NOTIFICATION_CC_EMAILS',
        'joseph.akinyanmi@emeraldcfze.com'
    ))))),
];
