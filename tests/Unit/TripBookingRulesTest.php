<?php

namespace Tests\Unit;

use App\Support\TripBookingRules;
use Carbon\Carbon;
use Tests\TestCase;

class TripBookingRulesTest extends TestCase
{
    public function test_out_of_state_local_requires_seven_day_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

        $tooSoon = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_OUT_OF_STATE_LOCAL,
            '2026-06-06'
        );
        $this->assertFalse($tooSoon['valid']);

        $ok = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_OUT_OF_STATE_LOCAL,
            '2026-06-08'
        );
        $this->assertTrue($ok['valid']);
    }

    public function test_international_requires_fourteen_day_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

        $tooSoon = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_INTERNATIONAL,
            '2026-06-10'
        );
        $this->assertFalse($tooSoon['valid']);

        $ok = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_INTERNATIONAL,
            '2026-06-15'
        );
        $this->assertTrue($ok['valid']);
    }

    public function test_normalizes_trip_type_labels(): void
    {
        $this->assertSame(
            TripBookingRules::SCOPE_OUT_OF_STATE_LOCAL,
            TripBookingRules::normalizeScope('Out of State (Local)')
        );
        $this->assertSame(
            TripBookingRules::SCOPE_INTERNATIONAL,
            TripBookingRules::normalizeScope('International (Out of Nigeria)')
        );
        $this->assertSame(
            TripBookingRules::SCOPE_OUT_OF_STATE_LOCAL,
            TripBookingRules::normalizeScope('outside_state')
        );
    }
}
