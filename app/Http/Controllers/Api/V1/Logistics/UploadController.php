<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use Illuminate\Http\Request;

class UploadController extends ApiController
{
    public function templates()
    {
        return $this->success([
            'templates' => [
                'trips' => [
                    'title',
                    'origin',
                    'destination',
                    'scheduled_departure_at',
                    'scheduled_arrival_at',
                ],
                'materials' => [
                    'material_code',
                    'name',
                    'quantity',
                    'trip_id',
                    'unit',
                ],
            ],
        ]);
    }
}
