<?php

namespace Tests\Unit;

use App\Support\FastCache;
use App\Support\ReportCache;
use Tests\TestCase;

class ReportCacheUsesFastCacheTest extends TestCase
{
    public function test_remember_stores_value_on_fast_cache_store(): void
    {
        $key = ReportCache::key('unit_test', ['a' => 1]);
        FastCache::store()->forget($key);

        $calls = 0;
        $value = ReportCache::remember($key, function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        });

        $this->assertSame(['ok' => true], $value);
        $this->assertSame(1, $calls);
        $this->assertSame(['ok' => true], FastCache::store()->get($key));

        $again = ReportCache::remember($key, function () use (&$calls) {
            $calls++;

            return ['ok' => false];
        });

        $this->assertSame(['ok' => true], $again);
        $this->assertSame(1, $calls);
    }
}
