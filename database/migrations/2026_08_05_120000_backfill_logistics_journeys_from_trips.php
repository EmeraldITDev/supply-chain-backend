<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent backfill: only updates journey rows when target fields are empty.
        DB::table('logistics_journeys')
            ->whereNotNull('trip_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];

                    // Fetch trip minimal info
                    $trip = DB::table('logistics_trips')->where('id', $row->trip_id)->first();
                    if (! $trip) {
                        continue;
                    }

                    // Vehicle plate from linked vehicle if journey missing it
                    if (empty($row->vehicle_plate_number) && property_exists($trip, 'vehicle_id') && $trip->vehicle_id) {
                        $plate = DB::table('logistics_vehicles')->where('id', $trip->vehicle_id)->value('plate_number');
                        if ($plate) {
                            $updates['vehicle_plate_number'] = $plate;
                        }
                    }

                    // Driver name from trip.driver_user_id or external_driver JSON
                    if (empty($row->driver_name)) {
                        if (property_exists($trip, 'driver_user_id') && $trip->driver_user_id) {
                            $dname = DB::table('users')->where('id', $trip->driver_user_id)->value('name');
                            if ($dname) {
                                $updates['driver_name'] = $dname;
                            }
                        } elseif (property_exists($trip, 'external_driver') && $trip->external_driver) {
                            $ext = is_string($trip->external_driver) ? json_decode($trip->external_driver, true) : (array) $trip->external_driver;
                            if (! empty($ext['name'])) {
                                $updates['driver_name'] = $ext['name'];
                            }
                        }
                    }

                    // Passengers: if journey.passengers empty, build from trip.passenger_user_ids
                    if ((empty($row->passengers) || $row->passengers === '[]' || $row->passengers === null)
                        && property_exists($trip, 'passenger_user_ids') && $trip->passenger_user_ids) {
                        $ids = is_string($trip->passenger_user_ids) ? json_decode($trip->passenger_user_ids, true) : (array) $trip->passenger_user_ids;
                        if (is_array($ids) && count($ids) > 0) {
                            $users = DB::table('users')->whereIn('id', $ids)->select('id','name','email','phone','department')->get();
                            $arr = $users->map(function ($u) {
                                return [
                                    'id' => $u->id,
                                    'name' => $u->name,
                                    'email' => $u->email,
                                    'phone' => $u->phone,
                                    'department' => $u->department,
                                ];
                            })->values()->all();

                            if (count($arr) > 0) {
                                $updates['passengers'] = json_encode($arr);
                            }
                        }
                    }

                    if (count($updates) > 0) {
                        DB::table('logistics_journeys')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // No-op: this migration backfills data and is intentionally irreversible.
    }
};
