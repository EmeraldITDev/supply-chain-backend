<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserEmailNormalizationTest extends TestCase
{
    public function test_normalize_email_lowercases_and_trims(): void
    {
        $this->assertSame('temitope.lawal@emeraldcfze.com', User::normalizeEmail('  Temitope.Lawal@EmeraldCFZE.com  '));
    }
}
