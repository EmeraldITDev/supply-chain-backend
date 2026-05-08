<?php

namespace Database\Seeders;

use App\Models\POTermsTemplate;
use Illuminate\Database\Seeder;

class POTermsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            'goods' => "Standard terms:\n- Deliver only brand-new and compliant goods.\n- Package contents must be clearly identified and accompanied by delivery documents.\n- Replace non-conforming goods at no additional cost.",
            'services' => "Standard terms:\n- Perform services in line with approved scope and timelines.\n- Submit progress evidence with invoice.\n- Rework non-conforming deliverables at no additional cost.",
            'logistics' => "Standard terms:\n- Adhere to agreed pickup and delivery windows.\n- Provide transport documentation and incident reports where applicable.\n- Maintain cargo integrity and compliance throughout transit.",
        ];

        foreach ($templates as $type => $content) {
            POTermsTemplate::updateOrCreate(
                ['po_type' => $type, 'is_active' => true],
                ['content' => $content]
            );
        }
    }
}
