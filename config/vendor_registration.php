<?php

/**
 * Vendor registration: compliance document slots and defaults.
 * All listed slots are optional (required=false) so registration cannot fail for missing uploads.
 */
return [

    'compliance_document_slots' => [
        [
            'key' => 'hse',
            'label' => 'HSE Documents',
            'description' => 'Health, Safety & Environment documents (e.g., ISO 9001, ISO 14001, ISO 45001, OHSAS 18001, Environmental Policy).',
            'expires_annually' => false,
            'required' => false,
        ],
        [
            'key' => 'nuprc',
            'label' => 'NUPRC (DPR)',
            'description' => 'Nigerian Upstream Petroleum Regulatory Commission permit (expires annually).',
            'expires_annually' => true,
            'required' => false,
        ],
        [
            'key' => 'pencom',
            'label' => 'PENCOM',
            'description' => 'Pension Commission compliance certificate (expires 31st December).',
            'expires_annually' => true,
            'required' => false,
        ],
        [
            'key' => 'itf',
            'label' => 'ITF',
            'description' => 'Industrial Training Fund certificate (expires 31st December).',
            'expires_annually' => true,
            'required' => false,
        ],
        [
            'key' => 'nsitf',
            'label' => 'NSITF',
            'description' => 'Nigeria Social Insurance Trust Fund certificate (expires 31st December).',
            'expires_annually' => true,
            'required' => false,
        ],
    ],

];
