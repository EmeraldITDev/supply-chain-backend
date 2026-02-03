<?php

namespace App\Services\Logistics;

use Illuminate\Support\Arr;

class UploadService
{
    public function validateRows(array $rows, array $requiredFields): array
    {
        $valid = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $missing = [];
            foreach ($requiredFields as $field) {
                if (!Arr::has($row, $field) || $row[$field] === null || $row[$field] === '') {
                    $missing[] = $field;
                }
            }

            if (count($missing) > 0) {
                $errors[] = [
                    'row' => $index + 1,
                    'missing' => $missing,
                ];
                continue;
            }

            $valid[] = $row;
        }

        return [$valid, $errors];
    }
}
