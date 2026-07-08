<?php

namespace Tests\Unit;

use App\Support\InternationalTransportModeRequest;
use App\Support\TripBookingRules;
use Illuminate\Http\Request;
use Tests\TestCase;

class InternationalTransportModeRequestTest extends TestCase
{
    public function test_resolve_returns_mode_for_international_scope(): void
    {
        $request = Request::create('/', 'POST', [
            'international_transport_mode' => 'flight',
        ]);

        $this->assertSame(
            'flight',
            InternationalTransportModeRequest::resolve($request, TripBookingRules::SCOPE_INTERNATIONAL)
        );
    }

    public function test_resolve_returns_null_for_non_international_scope(): void
    {
        $request = Request::create('/', 'POST', [
            'international_transport_mode' => 'flight',
        ]);

        $this->assertNull(
            InternationalTransportModeRequest::resolve($request, TripBookingRules::SCOPE_WITHIN_STATE)
        );
    }

    public function test_resolve_accepts_camel_case_alias(): void
    {
        $request = Request::create('/', 'POST', [
            'internationalTransportMode' => 'road',
        ]);

        $this->assertSame(
            'road',
            InternationalTransportModeRequest::resolve($request, TripBookingRules::SCOPE_INTERNATIONAL)
        );
    }

    public function test_resolve_for_update_preserves_existing_when_mode_not_sent(): void
    {
        $request = Request::create('/', 'PUT', [
            'bookingScope' => 'international',
        ]);

        $this->assertSame(
            'flight',
            InternationalTransportModeRequest::resolveForUpdate(
                $request,
                TripBookingRules::SCOPE_INTERNATIONAL,
                'flight'
            )
        );
    }

    public function test_resolve_for_update_clears_when_scope_is_not_international(): void
    {
        $request = Request::create('/', 'PUT', [
            'bookingScope' => 'within_state',
        ]);

        $this->assertNull(
            InternationalTransportModeRequest::resolveForUpdate(
                $request,
                TripBookingRules::SCOPE_WITHIN_STATE,
                'flight'
            )
        );
    }
}
