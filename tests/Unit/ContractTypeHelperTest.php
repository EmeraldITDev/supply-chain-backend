<?php

namespace Tests\Unit;

use App\Support\ContractTypeHelper;
use PHPUnit\Framework\TestCase;

class ContractTypeHelperTest extends TestCase
{
    public function test_standard_contract_types(): void
    {
        $this->assertTrue(ContractTypeHelper::isStandard('emerald'));
        $this->assertTrue(ContractTypeHelper::isStandard('OANDO'));
        $this->assertFalse(ContractTypeHelper::isStandard('custom vendor agreement'));
    }
}
