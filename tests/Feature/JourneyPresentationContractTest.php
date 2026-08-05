<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\Api\V1\Logistics\JourneyController;
use App\Models\Logistics\Journey;
use App\Models\Logistics\Trip;

class JourneyPresentationContractTest extends TestCase
{
    public function test_present_journey_includes_passengers_vehicle_and_driver()
    {
        $controller = $this->app->make(JourneyController::class);

        // Create a Journey instance in memory and attach a Trip with vehicle/driver
        $journey = new Journey();
        $journey->id = 1;
        $journey->vehicle_plate_number = null;
        $journey->vehicle_make = null;
        $journey->vehicle_model = null;
        $journey->driver_name = null;
        $journey->passengers = [];

        $trip = new Trip();
        $trip->id = 42;
        // vehicle and driver can be simple objects with properties
        $vehicle = (object) ['plate_number' => 'XYZ-123', 'make' => 'Toyota', 'model' => 'Hiace'];
        $driver = (object) ['name' => 'Jane Driver'];

        // Attach relations in-memory
        $trip->setRelation('vehicle', $vehicle);
        $trip->setRelation('driver', $driver);

        $journey->setRelation('trip', $trip);

        // Call private presentJourney via reflection
        $ref = new \ReflectionMethod(JourneyController::class, 'presentJourney');
        $ref->setAccessible(true);
        $result = $ref->invoke($controller, $journey);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('passengers', $result);
        $this->assertArrayHasKey('vehicle_plate_number', $result);
        $this->assertArrayHasKey('driver_name', $result);

        $this->assertEquals('XYZ-123', $result['vehicle_plate_number']);
        $this->assertEquals('Jane Driver', $result['driver_name']);
        $this->assertIsArray($result['passengers']);
    }
}
