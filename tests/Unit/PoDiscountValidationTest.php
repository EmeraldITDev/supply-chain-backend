<?php

namespace Tests\Unit;

use App\Http\Requests\Procurement\StorePurchaseOrderRequest;
use App\Http\Requests\Procurement\UpdatePurchaseOrderRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PoDiscountValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function cases(): iterable
    {
        yield 'no discount' => [['discountType' => null, 'discountValue' => null], true];
        yield 'percentage ok' => [['discountType' => 'percentage', 'discountValue' => 12.5], true];
        yield 'percentage over 100' => [['discountType' => 'percentage', 'discountValue' => 150], false];
        yield 'percentage of exactly 100' => [['discountType' => 'percentage', 'discountValue' => 100], false];
        yield 'amount over 100 is fine' => [['discountType' => 'amount', 'discountValue' => 150000], true];
        yield 'negative' => [['discountType' => 'amount', 'discountValue' => -5], false];
        yield 'bad type' => [['discountType' => 'bogus', 'discountValue' => 5], false];
    }

    /**
     * @dataProvider cases
     *
     * @param  array<string, mixed>  $input
     */
    public function test_po_request_discount_rules(array $input, bool $passes): void
    {
        foreach ([new UpdatePurchaseOrderRequest(), new StorePurchaseOrderRequest()] as $request) {
            $rules = array_intersect_key($request->rules(), array_flip(['discountType', 'discountValue']));
            $this->assertSame($passes, Validator::make($input, $rules)->passes(), $request::class);
        }
    }
}
