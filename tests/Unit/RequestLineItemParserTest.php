<?php

namespace Tests\Unit;

use App\Support\RequestLineItemParser;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestLineItemParserTest extends TestCase
{
    public function test_decodes_json_string_from_multipart(): void
    {
        $json = json_encode([
            ['itemName' => 'Chair', 'budgetAmount' => 100],
        ]);

        $request = Request::create('/mrfs', 'POST', ['items' => $json]);
        $resolved = RequestLineItemParser::resolve($request);

        $this->assertCount(1, $resolved);
        $this->assertSame('Chair', $resolved[0]['item_name']);
        $this->assertSame(100.0, $resolved[0]['budget_amount']);
    }

    public function test_prefers_line_items_key(): void
    {
        $request = Request::create('/mrfs', 'POST', [
            'line_items' => [
                ['item_name' => 'Desk', 'budget_amount' => 50],
            ],
        ]);

        $resolved = RequestLineItemParser::resolve($request);
        $this->assertSame('Desk', $resolved[0]['itemName']);
    }

    public function test_ignores_object_object_garbage(): void
    {
        $request = Request::create('/mrfs', 'POST', ['items' => '[object Object]']);
        $this->assertSame([], RequestLineItemParser::resolve($request));
    }
}
