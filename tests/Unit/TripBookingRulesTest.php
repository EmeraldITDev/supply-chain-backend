<?php

namespace Tests\Unit;

use App\Support\TripBookingRules;
use Carbon\Carbon;
use Tests\TestCase;

class TripBookingRulesTest extends TestCase
{
    public function test_within_state_requires_two_day_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

        $tooSoon = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_WITHIN_STATE,
            '2026-06-02'
        );
        $this->assertFalse($tooSoon['valid']);

        $ok = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_WITHIN_STATE,
            '2026-06-03'
        );
        $this->assertTrue($ok['valid']);
    }

    public function test_outside_state_requires_fourteen_day_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

        $tooSoon = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_OUTSIDE_STATE,
            '2026-06-10'
        );
        $this->assertFalse($tooSoon['valid']);

        $ok = TripBookingRules::validateDeparture(
            TripBookingRules::SCOPE_OUTSIDE_STATE,
            '2026-06-15'
        );
        $this->assertTrue($ok['valid']);
    }

    public function test_normalizes_trip_type_labels(): void
    {
        $this->assertSame(
            TripBookingRules::SCOPE_WITHIN_STATE,
            TripBookingRules::normalizeScope('Within State')
        );
        $this->assertSame(
            TripBookingRules::SCOPE_OUTSIDE_STATE,
            TripBookingRules::normalizeScope('outside_state')
        );
    }
}
