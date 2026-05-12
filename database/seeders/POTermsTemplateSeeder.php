<?php

namespace Database\Seeders;

use App\Models\POTermsTemplate;
use Illuminate\Database\Seeder;

class POTermsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = array_filter(
            config('po_terms_templates', []),
            static fn ($body) => is_string($body) && $body !== ''
        );

        foreach ($templates as $type => $content) {
            POTermsTemplate::updateOrCreate(
                ['po_type' => $type, 'is_active' => true],
                ['content' => $content]
            );
        }
    }
}
