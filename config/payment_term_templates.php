<?php

return [
    '100_advance' => [
        'key' => '100_advance',
        'name' => '100% Advance Payment',
        'milestones' => [
            [
                'milestone_number' => 1,
                'label' => 'Advance',
                'percentage' => 100,
                'trigger_condition' => 'on_advance',
                'required_documents' => ['signed_po', 'pfi'],
            ],
        ],
    ],
    '70_30_delivery' => [
        'key' => '70_30_delivery',
        'name' => '70% Advance / 30% Upon Delivery',
        'milestones' => [
            [
                'milestone_number' => 1,
                'label' => 'Advance',
                'percentage' => 70,
                'trigger_condition' => 'on_advance',
                'required_documents' => ['signed_po', 'pfi'],
            ],
            [
                'milestone_number' => 2,
                'label' => 'Upon Delivery',
                'percentage' => 30,
                'trigger_condition' => 'upon_delivery',
                'required_documents' => ['grn', 'waybill'],
            ],
        ],
    ],
    '50_50_completion' => [
        'key' => '50_50_completion',
        'name' => '50% Advance / 50% Upon Completion',
        'milestones' => [
            [
                'milestone_number' => 1,
                'label' => 'Advance',
                'percentage' => 50,
                'trigger_condition' => 'on_advance',
                'required_documents' => ['signed_po', 'pfi'],
            ],
            [
                'milestone_number' => 2,
                'label' => 'Upon Completion',
                'percentage' => 50,
                'trigger_condition' => 'upon_completion',
                'required_documents' => ['jcc'],
            ],
        ],
    ],
    '30_40_30_mixed' => [
        'key' => '30_40_30_mixed',
        'name' => '30% Advance / 40% Upon Delivery / 30% Upon Completion',
        'milestones' => [
            [
                'milestone_number' => 1,
                'label' => 'Advance',
                'percentage' => 30,
                'trigger_condition' => 'on_advance',
                'required_documents' => ['signed_po', 'pfi'],
            ],
            [
                'milestone_number' => 2,
                'label' => 'Upon Delivery',
                'percentage' => 40,
                'trigger_condition' => 'upon_delivery',
                'required_documents' => ['grn', 'waybill'],
            ],
            [
                'milestone_number' => 3,
                'label' => 'Upon Completion',
                'percentage' => 30,
                'trigger_condition' => 'upon_completion',
                'required_documents' => ['jcc'],
            ],
        ],
    ],
];
