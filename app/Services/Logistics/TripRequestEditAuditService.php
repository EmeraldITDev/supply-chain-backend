<?php

namespace App\Services\Logistics;

use App\Models\Logistics\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TripRequestEditAuditService
{
    /**
     * @param  array<string, mixed> $before
     * @param  array<string, mixed> $after
     * @return list<array<string, mixed>>
     */
    public function logChanges(Trip $trip, User $editor, array $before, array $after, ?string $reason = null): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $original = $before[$field] ?? null;
            if ($this->sameValue($original, $value)) {
                continue;
            }

            $entry = [
                'trip_request_id' => $trip->id,
                'edited_by' => $editor->id,
                'field_name' => $field,
                'original_value' => $this->normalize($original),
                'new_value' => $this->normalize($value),
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('trip_request_edits')->insert($entry);
            $changes[] = $entry;
        }

        return $changes;
    }

    public function getAuditTrail(Trip $trip): array
    {
        return DB::table('trip_request_edits')
            ->where('trip_request_id', $trip->id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($row) {
                $editor = User::find($row->edited_by);

                return [
                    'id' => $row->id,
                    'trip_request_id' => $row->trip_request_id,
                    'field_name' => $row->field_name,
                    'original_value' => $this->decode($row->original_value),
                    'new_value' => $this->decode($row->new_value),
                    'reason' => $row->reason,
                    'edited_by' => $row->edited_by,
                    'editor_name' => $editor?->name,
                    'created_at' => $row->created_at,
                ];
            })
            ->values()
            ->all();
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return json_encode($left) === json_encode($right);
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    private function decode(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
